#!/usr/bin/env php
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Jw\PowerCache\Contracts\PowerCacheStoreInterface;

function abortDrill(string $message): never
{
    fwrite(STDERR, $message.PHP_EOL);
    exit(1);
}

function atomicWrite(string $path, string $contents): void
{
    $directory = dirname($path);
    $temporary = $directory.'/.'.basename($path).'.restore-'.bin2hex(random_bytes(6));
    $mode = is_file($path) ? (fileperms($path) & 0777) : 0600;

    if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
        throw new RuntimeException("Failed to write temporary restore file: {$temporary}");
    }
    chmod($temporary, $mode);
    if (! rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Failed to atomically replace restored file: {$path}");
    }
}

/** @return array<int, array<string, mixed>> */
function tableRows(string $table, string $orderColumn): array
{
    return DB::table($table)
        ->orderBy($orderColumn)
        ->get()
        ->map(static fn (object $row): array => (array) $row)
        ->all();
}

/** @param array<int, array<string, mixed>> $stateRows @param array<int, array<string, mixed>> $outboxRows */
function restoreDatabaseRows(array $stateRows, array $outboxRows): void
{
    DB::transaction(static function () use ($stateRows, $outboxRows): void {
        DB::table('jw_power_cache_invalidation_outbox')->delete();
        DB::table('jw_power_cache_state')->delete();
        if ($stateRows !== []) {
            DB::table('jw_power_cache_state')->insert($stateRows);
        }
        if ($outboxRows !== []) {
            DB::table('jw_power_cache_invalidation_outbox')->insert($outboxRows);
        }
    });
}

if (getenv('JWPC_RESTORE_DRILL_ALLOW_DESTRUCTIVE') !== '1') {
    abortDrill('JWPC_RESTORE_DRILL_ALLOW_DESTRUCTIVE=1 is required.');
}

$g7Root = realpath((string) getenv('G7_ROOT'));
if ($g7Root === false
    || ! is_file($g7Root.'/vendor/autoload.php')
    || ! is_file($g7Root.'/bootstrap/app.php')) {
    abortDrill('G7_ROOT must point to an installed Gnuboard 7 instance.');
}

require $g7Root.'/vendor/autoload.php';
$app = require $g7Root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment(['local', 'testing'])) {
    abortDrill('The restore drill only runs in a local or testing application environment.');
}

$database = (string) DB::connection()->getDatabaseName();
$expectedDatabase = trim((string) getenv('JWPC_RESTORE_DRILL_EXPECT_DATABASE'));
if ($expectedDatabase === '' || ! hash_equals($expectedDatabase, $database)) {
    abortDrill('JWPC_RESTORE_DRILL_EXPECT_DATABASE must exactly match the connected database.');
}

foreach (['jw_power_cache_state', 'jw_power_cache_invalidation_outbox'] as $table) {
    if (! Schema::hasTable($table)) {
        abortDrill("Required table is missing: {$table}");
    }
}

$settingsPath = $g7Root.'/storage/app/plugins/jw-power_cache/settings/setting.json';
$settingsRaw = is_file($settingsPath) ? file_get_contents($settingsPath) : false;
if (! is_string($settingsRaw)) {
    abortDrill('JW PowerCache settings file is missing.');
}
$settings = json_decode($settingsRaw, true, flags: JSON_THROW_ON_ERROR);
if (($settings['mode'] ?? null) !== 'bypass') {
    abortDrill('Set JW PowerCache mode to bypass before running the restore drill.');
}

$stateRows = tableRows('jw_power_cache_state', 'state_key');
$outboxRows = tableRows('jw_power_cache_invalidation_outbox', 'id');
$state = array_column($stateRows, 'state_value', 'state_key');
if (($state['site_id'] ?? '') === ''
    || ($state['runtime_epoch'] ?? '') === ''
    || (int) ($state['dirty_event_id'] ?? -1) !== 0
    || DB::table('jw_power_cache_invalidation_outbox')->whereNull('applied_at')->exists()) {
    abortDrill('The pre-drill state must be healthy with no dirty or pending outbox event.');
}

$backup = [
    'format' => 1,
    'database' => $database,
    'created_at' => gmdate(DATE_ATOM),
    'settings_sha256' => hash('sha256', $settingsRaw),
    'settings_base64' => base64_encode($settingsRaw),
    'state_rows' => $stateRows,
    'outbox_rows' => $outboxRows,
];
$backupJson = json_encode(
    $backup,
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
);
$backupSha = hash('sha256', $backupJson);
$temporaryDirectory = sys_get_temp_dir().'/jwpc-restore-drill-'.bin2hex(random_bytes(8));
if (! mkdir($temporaryDirectory, 0700)) {
    abortDrill('Failed to create a private temporary backup directory.');
}
$backupPath = $temporaryDirectory.'/backup.json';
file_put_contents($backupPath, $backupJson, LOCK_EX);
chmod($backupPath, 0600);

$wasDown = $app->isDownForMaintenance();
$mutated = false;
$store = $app->make(PowerCacheStoreInterface::class);
$sentinelKey = 'restore-drill-'.bin2hex(random_bytes(8));

try {
    if (! $wasDown && Artisan::call('down', ['--retry' => 60]) !== 0) {
        throw new RuntimeException('Failed to enter maintenance mode.');
    }
    if (! $app->isDownForMaintenance()) {
        throw new RuntimeException('Maintenance mode did not become active.');
    }

    $store->putResponse($sentinelKey, [
        'runtime_epoch' => $state['runtime_epoch'],
        'body' => 'must-not-be-reused-after-restore',
    ], 3600);

    $mutated = true;
    $mutatedSettings = $settings;
    $mutatedSettings['max_response_kb'] = ((int) ($settings['max_response_kb'] ?? 512)) === 513 ? 514 : 513;
    atomicWrite($settingsPath, json_encode(
        $mutatedSettings,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ).PHP_EOL);

    DB::transaction(static function (): void {
        $now = now();
        DB::table('jw_power_cache_state')->where('state_key', 'site_id')->update([
            'state_value' => 'restore-drill-corrupt-site',
            'updated_at' => $now,
        ]);
        DB::table('jw_power_cache_state')->where('state_key', 'runtime_epoch')->update([
            'state_value' => 'restore-drill-corrupt-epoch',
            'updated_at' => $now,
        ]);
        $eventId = (int) DB::table('jw_power_cache_invalidation_outbox')->insertGetId([
            'scopes' => json_encode(['site'], JSON_THROW_ON_ERROR),
            'reason' => 'restore-drill-corruption',
            'payload' => json_encode(['drill' => true], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'last_error' => null,
            'applied_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('jw_power_cache_state')->where('state_key', 'dirty_event_id')->update([
            'state_value' => (string) $eventId,
            'updated_at' => $now,
        ]);
    });
    $storedBackup = json_decode(
        (string) file_get_contents($backupPath),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    restoreDatabaseRows($storedBackup['state_rows'], $storedBackup['outbox_rows']);
    atomicWrite($settingsPath, base64_decode($storedBackup['settings_base64'], true));

    $exitCode = Artisan::call('power-cache:restore-finalize', [
        '--yes' => true,
        '--json' => true,
    ]);
    $finalizeOutput = trim(Artisan::output());
    $finalize = json_decode($finalizeOutput, true, flags: JSON_THROW_ON_ERROR);
    if ($exitCode !== 0 || ! ($finalize['ok'] ?? false)) {
        throw new RuntimeException('Restore finalization command failed: '.$finalizeOutput);
    }

    $afterRows = tableRows('jw_power_cache_state', 'state_key');
    $afterState = array_column($afterRows, 'state_value', 'state_key');
    $control = $store->controlBarrier();
    $runtime = $store->runtimeSnapshot();
    $staleEntry = $store->getResponse($sentinelKey);
    $settingsAfter = (string) file_get_contents($settingsPath);

    $checks = [
        'settings_exact' => hash_equals($backup['settings_sha256'], hash('sha256', $settingsAfter)),
        'site_id_preserved' => hash_equals((string) $state['site_id'], (string) ($afterState['site_id'] ?? '')),
        'runtime_epoch_rotated' => ($afterState['runtime_epoch'] ?? '') !== $state['runtime_epoch']
            && ($afterState['runtime_epoch'] ?? '') !== 'restore-drill-corrupt-epoch',
        'dirty_cleared' => (int) ($afterState['dirty_event_id'] ?? -1) === 0,
        'outbox_restored' => tableRows('jw_power_cache_invalidation_outbox', 'id') === $outboxRows,
        'barrier_clean' => $control !== null && ! $control->dirty,
        'runtime_snapshot_current' => $runtime !== null
            && hash_equals((string) ($afterState['runtime_epoch'] ?? ''), $runtime->runtimeEpoch),
        'old_response_epoch_rejected' => is_array($staleEntry)
            && ($staleEntry['runtime_epoch'] ?? null) === $state['runtime_epoch']
            && ($staleEntry['runtime_epoch'] ?? null) !== ($afterState['runtime_epoch'] ?? null),
    ];
    if (in_array(false, $checks, true)) {
        throw new RuntimeException('One or more restore verification checks failed.');
    }

    $doctorExit = Artisan::call('power-cache:doctor', ['--json' => true]);
    $doctor = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    if ($doctorExit !== 0 || ! ($doctor['ok'] ?? false)) {
        throw new RuntimeException('Post-restore doctor failed.');
    }

    echo json_encode([
        'ok' => true,
        'database' => $database,
        'backup_sha256' => $backupSha,
        'settings_sha256' => $backup['settings_sha256'],
        'site_id' => $afterState['site_id'],
        'previous_runtime_epoch' => $state['runtime_epoch'],
        'runtime_epoch' => $afterState['runtime_epoch'],
        'state_rows' => count($afterRows),
        'outbox_rows' => count($outboxRows),
        'checks' => $checks,
        'doctor_warnings' => count($doctor['warnings'] ?? []),
        'doctor_errors' => count($doctor['errors'] ?? []),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $e) {
    if ($mutated) {
        try {
            restoreDatabaseRows($stateRows, $outboxRows);
            atomicWrite($settingsPath, $settingsRaw);
            Artisan::call('power-cache:restore-finalize', ['--yes' => true, '--json' => true]);
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'Emergency cleanup failed: '.$cleanupError->getMessage().PHP_EOL);
        }
    }

    abortDrill($e->getMessage());
} finally {
    if (! $wasDown) {
        Artisan::call('up');
    }
    @unlink($backupPath);
    @rmdir($temporaryDirectory);
}
