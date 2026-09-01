#!/usr/bin/env php
<?php

declare(strict_types=1);

use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Page\Models\Page;
use Modules\Sirsoft\Page\Services\PageService;
use Plugins\Jw\PowerCache\Contracts\InvalidationRepositoryInterface;

$options = getopt('', [
    'g7-root:',
    'base-url:',
    'redis-container:',
    'duration::',
    'concurrency::',
    'rps::',
    'user-id::',
    'output:',
]);

$context = [
    'g7_root' => '',
    'base_url' => '',
    'redis_container' => '',
    'duration' => 120,
    'concurrency' => 16,
    'rps' => 8,
    'user_id' => 1,
    'output' => '',
];

try {
    $context = parseConfiguration($options);
    $result = runCampaign($context);
    writeJson($context['output'], $result);
    $consoleResult = $result;
    unset($consoleResult['samples']);
    $consoleResult['evidence_file'] = $context['output'];
    fwrite(STDOUT, json_encode($consoleResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit($result['pass'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'fault-campaign: '.$e->getMessage().PHP_EOL);
    exit(2);
}

/** @return array{g7_root:string,base_url:string,redis_container:string,duration:int,concurrency:int,rps:int,user_id:int,output:string} */
function parseConfiguration(array $options): array
{
    if (getenv('JWPC_BENCH_ISOLATED') !== '1') {
        throw new RuntimeException('JWPC_BENCH_ISOLATED=1 is required because the campaign mutates data and restarts Redis.');
    }

    $g7Root = rtrim((string) ($options['g7-root'] ?? ''), DIRECTORY_SEPARATOR);
    $baseUrl = rtrim((string) ($options['base-url'] ?? ''), '/');
    $redisContainer = trim((string) ($options['redis-container'] ?? ''));
    $duration = filter_var($options['duration'] ?? 120, FILTER_VALIDATE_INT);
    $concurrency = filter_var($options['concurrency'] ?? 16, FILTER_VALIDATE_INT);
    $rps = filter_var($options['rps'] ?? 8, FILTER_VALIDATE_INT);
    $userId = filter_var($options['user-id'] ?? 1, FILTER_VALIDATE_INT);
    $output = trim((string) ($options['output'] ?? ''));

    if (! is_file($g7Root.'/bootstrap/app.php') || ! is_file($g7Root.'/artisan')) {
        throw new InvalidArgumentException('--g7-root must point to an installed Gnuboard 7 instance.');
    }
    if ($baseUrl === '' || preg_match('#^https?://#', $baseUrl) !== 1) {
        throw new InvalidArgumentException('--base-url must be an http(s) URL.');
    }
    if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/', $redisContainer) !== 1) {
        throw new InvalidArgumentException('--redis-container must be an exact Docker container name.');
    }
    if ($duration === false || $duration < 30 || $duration > 1800) {
        throw new InvalidArgumentException('--duration must be between 30 and 1800 seconds.');
    }
    if ($concurrency === false || $concurrency < 1 || $concurrency > 64) {
        throw new InvalidArgumentException('--concurrency must be between 1 and 64.');
    }
    if ($rps === false || $rps < 1 || $rps > 9) {
        throw new InvalidArgumentException('--rps must be between 1 and 9 to stay below the shared guest throttle.');
    }
    if ($userId === false || $userId < 1) {
        throw new InvalidArgumentException('--user-id must be a positive integer.');
    }
    if ($output === '') {
        throw new InvalidArgumentException('--output is required.');
    }
    if (file_exists($output)) {
        throw new RuntimeException("Evidence output already exists and will not be overwritten: {$output}");
    }

    return [
        'g7_root' => $g7Root,
        'base_url' => $baseUrl,
        'redis_container' => $redisContainer,
        'duration' => $duration,
        'concurrency' => $concurrency,
        'rps' => $rps,
        'user_id' => $userId,
        'output' => $output,
    ];
}

/** @param array{g7_root:string,base_url:string,redis_container:string,duration:int,concurrency:int,rps:int,user_id:int,output:string} $config */
function runCampaign(array $config): array
{
    if (! chdir($config['g7_root'])) {
        throw new RuntimeException("Unable to enter Gnuboard root: {$config['g7_root']}");
    }
    require_once $config['g7_root'].'/vendor/autoload.php';
    $app = require $config['g7_root'].'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();

    if (! dockerContainerRunning($config['redis_container'])) {
        throw new RuntimeException("Redis container is not running: {$config['redis_container']}");
    }
    if (dockerContainerAutoRemove($config['redis_container'])) {
        throw new RuntimeException('Redis container uses auto-remove and cannot be safely restarted; recreate it without --rm.');
    }

    $service = $app->make(PageService::class);
    $repository = $app->make(InvalidationRepositoryInterface::class);
    $slug = 'jwpc-fault-'.bin2hex(random_bytes(6));
    $url = $config['base_url'].'/api/modules/sirsoft-page/pages/'.$slug;
    $page = null;
    $samples = [];
    $actions = [];
    $startedAt = microtime(true);

    try {
        Auth::loginUsingId($config['user_id']);
        if (Auth::id() === null) {
            throw new RuntimeException("Unable to authenticate benchmark owner {$config['user_id']}.");
        }

        $initialToken = 'v0-'.bin2hex(random_bytes(8));
        $currentToken = $initialToken;
        $page = $service->createPage(pageData($slug, $currentToken));

        artisan('cache:clear');
        artisan('power-cache:mode', ['mode' => 'active', '--json' => true]);
        artisan('power-cache:purge', ['--scope' => 'page', '--reason' => 'fault-campaign-bootstrap', '--json' => true]);

        $warm = waitForExpectedResponse($url, $currentToken, 20);
        $expectedChecksum = $warm['checksum'];
        $initialEpoch = $repository->snapshot()->runtimeEpoch;

        $batchPeriod = $config['concurrency'] / $config['rps'];
        $totalBatches = max(20, (int) floor($config['duration'] / $batchPeriod));
        $actionBatches = [
            max(2, (int) floor($totalBatches * 0.20)) => 'mutation',
            max(3, (int) floor($totalBatches * 0.40)) => 'purge',
            max(4, (int) floor($totalBatches * 0.60)) => 'selective_key_loss',
            max(5, (int) floor($totalBatches * 0.80)) => 'redis_restart',
        ];

        for ($batch = 1; $batch <= $totalBatches; $batch++) {
            $batchStartedAt = microtime(true);
            $action = $actionBatches[$batch] ?? null;
            $pending = startBatch($url, $config['concurrency']);

            if ($action === 'mutation') {
                $nextToken = 'v1-'.bin2hex(random_bytes(8));
                $eventBefore = latestOutboxId();
                $page = $service->updatePage($page->fresh(), pageData($slug, $nextToken));
                $eventAfter = latestOutboxId();
                $actions[] = actionEvidence($action, $startedAt, [
                    'token_before' => $currentToken,
                    'token_after' => $nextToken,
                    'page_version' => $page->current_version,
                    'event_before' => $eventBefore,
                    'event_after' => $eventAfter,
                    'advanced' => $eventAfter > $eventBefore,
                ]);
                collectBatch($pending, $samples, $batch, $action, [$currentToken, $nextToken], null);
                $currentToken = $nextToken;
                $probe = waitForExpectedResponse($url, $currentToken, 20);
                $expectedChecksum = $probe['checksum'];
                $samples[] = probeSample($probe, $batch, 'post_mutation_probe');
            } elseif ($action === 'purge') {
                $beforeEvent = latestOutboxId();
                artisan('power-cache:purge', ['--scope' => 'page', '--reason' => 'fault-campaign', '--json' => true]);
                $afterEvent = latestOutboxId();
                $actions[] = actionEvidence($action, $startedAt, [
                    'event_before' => $beforeEvent,
                    'event_after' => $afterEvent,
                    'advanced' => $afterEvent > $beforeEvent,
                ]);
                collectBatch($pending, $samples, $batch, $action, [$currentToken], $expectedChecksum);
            } elseif ($action === 'selective_key_loss') {
                $epochBefore = $repository->snapshot()->runtimeEpoch;
                $deleted = deleteGenerationKey('page:all');
                collectBatch($pending, $samples, $batch, $action, [$currentToken], $expectedChecksum);
                $probe = waitForExpectedResponse($url, $currentToken, 20, $repository, $epochBefore);
                $epochAfter = $repository->snapshot()->runtimeEpoch;
                $actions[] = actionEvidence($action, $startedAt, [
                    'deleted_keys' => $deleted,
                    'epoch_before' => $epochBefore,
                    'epoch_after' => $epochAfter,
                    'epoch_rotated' => $epochAfter !== $epochBefore,
                ]);
                $expectedChecksum = $probe['checksum'];
                $samples[] = probeSample($probe, $batch, 'post_key_loss_probe');
            } elseif ($action === 'redis_restart') {
                $epochBefore = $repository->snapshot()->runtimeEpoch;
                docker(['restart', '--time', '1', $config['redis_container']]);
                collectBatch($pending, $samples, $batch, $action, [$currentToken], $expectedChecksum);
                $app['redis']->purge('jw_power_cache');
                $probe = waitForExpectedResponse($url, $currentToken, 30, $repository, $epochBefore);
                $epochAfter = $repository->snapshot()->runtimeEpoch;
                $actions[] = actionEvidence($action, $startedAt, [
                    'epoch_before' => $epochBefore,
                    'epoch_after' => $epochAfter,
                    'epoch_rotated' => $epochAfter !== $epochBefore,
                    'redis_running' => dockerContainerRunning($config['redis_container']),
                ]);
                $expectedChecksum = $probe['checksum'];
                $samples[] = probeSample($probe, $batch, 'post_redis_restart_probe');
            } else {
                collectBatch($pending, $samples, $batch, null, [$currentToken], $expectedChecksum);
            }

            $remaining = $batchPeriod - (microtime(true) - $batchStartedAt);
            if ($remaining > 0) {
                usleep((int) ($remaining * 1_000_000));
            }
        }

        $finishedAt = microtime(true);
        $summary = summarizeCampaign($samples, $actions, $startedAt, $finishedAt, $initialEpoch, $repository->snapshot()->runtimeEpoch);

        artisan('power-cache:mode', ['mode' => 'bypass', '--json' => true]);
        Auth::loginUsingId($config['user_id']);
        $service->deletePage($page->fresh());
        $page = null;
        artisan('power-cache:reconcile', ['--limit' => 100, '--json' => true]);
        $cleanup = [
            'mode' => 'bypass',
            'page_removed' => ! Page::query()->where('slug', $slug)->exists(),
            'pending_outbox' => $repository->pendingCount(),
        ];
        if (! $cleanup['page_removed'] || $cleanup['pending_outbox'] !== 0) {
            throw new RuntimeException('Campaign cleanup did not restore a clean database state.');
        }

        return [
            'schema_version' => 1,
            'pass' => $summary['pass'],
            'started_at' => gmdate(DATE_ATOM, (int) $startedAt),
            'finished_at' => gmdate(DATE_ATOM, (int) $finishedAt),
            'environment' => [
                'base_url' => $config['base_url'],
                'g7_root' => $config['g7_root'],
                'redis_container' => $config['redis_container'],
                'duration_seconds' => round($finishedAt - $startedAt, 3),
                'concurrency' => $config['concurrency'],
                'target_requests_per_second' => $config['rps'],
                'page_slug' => $slug,
            ],
            'summary' => $summary,
            'actions' => $actions,
            'cleanup' => $cleanup,
            'samples' => $samples,
        ];
    } finally {
        try {
            $app['redis']->purge('jw_power_cache');
        } catch (Throwable) {
        }
        try {
            artisan('power-cache:mode', ['mode' => 'bypass', '--json' => true]);
        } catch (Throwable) {
        }
        if ($page instanceof Page) {
            try {
                Auth::loginUsingId($config['user_id']);
                $service->deletePage($page->fresh());
            } catch (Throwable) {
            }
        }
    }
}

/** @return array<string,mixed> */
function pageData(string $slug, string $token): array
{
    return [
        'slug' => $slug,
        'title' => ['ko' => "JWPC fault {$token}", 'en' => "JWPC fault {$token}"],
        'content' => ['ko' => "<p>JWPC_FAULT_TOKEN:{$token}</p>", 'en' => "<p>JWPC_FAULT_TOKEN:{$token}</p>"],
        'content_mode' => 'html',
        'published' => true,
        'seo_meta' => [],
    ];
}

/** @param array<string,mixed> $arguments */
function artisan(string $command, array $arguments = []): void
{
    $status = Artisan::call($command, $arguments);
    if ($status !== 0) {
        throw new RuntimeException("Artisan {$command} failed: ".trim(Artisan::output()));
    }
}

/** @return array{multi:CurlMultiHandle,handles:array<int,array{handle:CurlHandle,started_at:float}>} */
function startBatch(string $url, int $concurrency): array
{
    $multi = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < $concurrency; $i++) {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Language: ko',
                'User-Agent: JW-PowerCache-Fault-Campaign/1.0',
            ],
        ]);
        $handles[spl_object_id($handle)] = ['handle' => $handle, 'started_at' => microtime(true)];
        curl_multi_add_handle($multi, $handle);
    }

    do {
        $status = curl_multi_exec($multi, $running);
    } while ($status === CURLM_CALL_MULTI_PERFORM);
    if ($status !== CURLM_OK) {
        throw new RuntimeException('Unable to start HTTP batch: '.curl_multi_strerror($status));
    }

    return ['multi' => $multi, 'handles' => $handles];
}

/**
 * @param  array{multi:CurlMultiHandle,handles:array<int,array{handle:CurlHandle,started_at:float}>}  $pending
 * @param  array<int,array<string,mixed>>  $samples
 * @param  array<int,string>  $allowedTokens
 */
function collectBatch(array $pending, array &$samples, int $batch, ?string $overlapAction, array $allowedTokens, ?string $expectedChecksum): void
{
    $multi = $pending['multi'];
    $handles = $pending['handles'];
    do {
        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        if ($status !== CURLM_OK) {
            throw new RuntimeException('HTTP batch failed: '.curl_multi_strerror($status));
        }
        if ($running > 0) {
            curl_multi_select($multi, 0.2);
        }
    } while ($running > 0);

    foreach ($handles as $meta) {
        $handle = $meta['handle'];
        $payload = curl_multi_getcontent($handle);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $headers = substr($payload, 0, $headerSize);
        $body = substr($payload, $headerSize);
        $decoded = json_decode($body, true);
        $token = extractToken($decoded);
        $checksum = hash('sha256', $body);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        $personalized = containsForbiddenKey($decoded);
        $stale = $token === null || ! in_array($token, $allowedTokens, true)
            || ($expectedChecksum !== null && ! hash_equals($expectedChecksum, $checksum));

        $samples[] = [
            'batch' => $batch,
            'overlap_action' => $overlapAction,
            'started_at_unix' => round($meta['started_at'], 6),
            'finished_at_unix' => round(microtime(true), 6),
            'latency_ms' => round((float) curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000, 3),
            'status' => $httpStatus,
            'cache' => responseHeader($headers, 'X-JW-Power-Cache') ?? 'missing',
            'checksum' => $checksum,
            'token' => $token,
            'current_version' => is_array($decoded) ? ($decoded['data']['current_version'] ?? null) : null,
            'error' => $error !== '' ? $error : null,
            'stale' => $stale,
            'personalized' => $personalized,
        ];

        curl_multi_remove_handle($multi, $handle);
    }
    curl_multi_close($multi);
}

/** @return array{checksum:string,token:string,status:int,cache:string,latency_ms:float} */
function waitForExpectedResponse(
    string $url,
    string $expectedToken,
    int $timeoutSeconds,
    ?InvalidationRepositoryInterface $repository = null,
    ?string $previousEpoch = null,
): array {
    $deadline = microtime(true) + $timeoutSeconds;
    $lastError = 'no response';
    do {
        $startedAt = microtime(true);
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Accept-Language: ko'],
        ]);
        $payload = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $headers = is_string($payload) ? substr($payload, 0, $headerSize) : '';
        $body = is_string($payload) ? substr($payload, $headerSize) : '';
        $decoded = json_decode($body, true);
        $token = extractToken($decoded);
        $epochReady = $repository === null || $previousEpoch === null
            || $repository->snapshot()->runtimeEpoch !== $previousEpoch;

        if ($status === 200 && $token === $expectedToken && ! containsForbiddenKey($decoded) && $epochReady) {
            return [
                'checksum' => hash('sha256', $body),
                'token' => $token,
                'status' => $status,
                'cache' => responseHeader($headers, 'X-JW-Power-Cache') ?? 'missing',
                'latency_ms' => round((microtime(true) - $startedAt) * 1000, 3),
            ];
        }
        $lastError = "HTTP {$status}, token=".($token ?? 'missing').', epoch_ready='.($epochReady ? 'yes' : 'no');
        usleep(100_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("Expected response did not recover within {$timeoutSeconds}s: {$lastError}");
}

/** @param array{checksum:string,token:string,status:int,cache:string,latency_ms:float} $probe @return array<string,mixed> */
function probeSample(array $probe, int $batch, string $label): array
{
    return [
        'batch' => $batch,
        'overlap_action' => $label,
        'started_at_unix' => null,
        'finished_at_unix' => round(microtime(true), 6),
        'latency_ms' => $probe['latency_ms'],
        'status' => $probe['status'],
        'cache' => $probe['cache'],
        'checksum' => $probe['checksum'],
        'token' => $probe['token'],
        'current_version' => null,
        'error' => null,
        'stale' => false,
        'personalized' => false,
    ];
}

function extractToken(mixed $decoded): ?string
{
    $content = is_array($decoded) ? ($decoded['data']['content'] ?? null) : null;
    if (! is_string($content) || preg_match('/JWPC_FAULT_TOKEN:([a-z0-9-]+)/', $content, $matches) !== 1) {
        return null;
    }

    return $matches[1];
}

function containsForbiddenKey(mixed $value): bool
{
    if (! is_array($value)) {
        return false;
    }
    $forbidden = ['user', 'creator', 'updater', 'email', 'mobile', 'phone', 'password', 'remember_token'];
    foreach ($value as $key => $child) {
        if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
            return true;
        }
        if (containsForbiddenKey($child)) {
            return true;
        }
    }

    return false;
}

function responseHeader(string $headers, string $name): ?string
{
    $value = null;
    foreach (preg_split('/\r?\n/', trim($headers)) ?: [] as $line) {
        if (stripos($line, $name.':') === 0) {
            $value = trim(substr($line, strlen($name) + 1));
        }
    }

    return $value;
}

function deleteGenerationKey(string $scope): int
{
    $storeName = (string) config('jw_power_cache.stores.redis');
    $store = Cache::store($storeName)->getStore();
    if (! $store instanceof RedisStore) {
        throw new RuntimeException('Selective key loss requires the Redis store.');
    }
    $key = $store->getPrefix().'generation:'.hash('sha256', $scope);
    $deleted = $store->connection()->command('del', [$key]);

    return is_numeric($deleted) ? (int) $deleted : 0;
}

/** @param array<string,mixed> $details @return array<string,mixed> */
function actionEvidence(string $action, float $campaignStartedAt, array $details): array
{
    return [
        'action' => $action,
        'completed_offset_seconds' => round(microtime(true) - $campaignStartedAt, 3),
        ...$details,
    ];
}

/** @param array<int,array<string,mixed>> $samples @param array<int,array<string,mixed>> $actions @return array<string,mixed> */
function summarizeCampaign(array $samples, array $actions, float $startedAt, float $finishedAt, string $initialEpoch, string $finalEpoch): array
{
    $requests = count($samples);
    $errors = array_filter($samples, static fn (array $sample): bool => $sample['error'] !== null || $sample['status'] !== 200);
    $stale = array_filter($samples, static fn (array $sample): bool => $sample['stale']);
    $personalized = array_filter($samples, static fn (array $sample): bool => $sample['personalized']);
    $latencies = array_column($samples, 'latency_ms');
    sort($latencies, SORT_NUMERIC);
    $actionPass = count($actions) === 4
        && array_reduce($actions, static function (bool $carry, array $action): bool {
            if (in_array($action['action'], ['mutation', 'purge'], true)) {
                return $carry && ($action['advanced'] ?? false) === true;
            }
            if (in_array($action['action'], ['selective_key_loss', 'redis_restart'], true)) {
                $valid = ($action['epoch_rotated'] ?? false) === true;
                if ($action['action'] === 'selective_key_loss') {
                    $valid = $valid && ($action['deleted_keys'] ?? 0) === 1;
                }
                if ($action['action'] === 'redis_restart') {
                    $valid = $valid && ($action['redis_running'] ?? false) === true;
                }

                return $carry && $valid;
            }

            return $carry;
        }, true);

    return [
        'pass' => $requests > 0 && count($errors) === 0 && count($stale) === 0 && count($personalized) === 0 && $actionPass,
        'requests' => $requests,
        'elapsed_seconds' => round($finishedAt - $startedAt, 3),
        'requests_per_second' => round($requests / max(0.001, $finishedAt - $startedAt), 2),
        'errors' => count($errors),
        'stale_responses' => count($stale),
        'personalized_responses' => count($personalized),
        'p50_ms' => percentile($latencies, 0.50),
        'p95_ms' => percentile($latencies, 0.95),
        'p99_ms' => percentile($latencies, 0.99),
        'status_counts' => counts($samples, 'status'),
        'cache_counts' => counts($samples, 'cache'),
        'checksum_cardinality' => count(array_unique(array_column($samples, 'checksum'))),
        'action_gate' => $actionPass,
        'initial_epoch' => $initialEpoch,
        'final_epoch' => $finalEpoch,
        'epoch_changed' => $initialEpoch !== $finalEpoch,
    ];
}

function latestOutboxId(): int
{
    return (int) (DB::table('jw_power_cache_invalidation_outbox')->max('id') ?? 0);
}

/** @param array<int,float|int> $sorted */
function percentile(array $sorted, float $percentile): ?float
{
    if ($sorted === []) {
        return null;
    }
    $rank = (int) ceil($percentile * count($sorted)) - 1;

    return round((float) $sorted[max(0, min($rank, count($sorted) - 1))], 3);
}

/** @param array<int,array<string,mixed>> $samples @return array<string,int> */
function counts(array $samples, string $key): array
{
    $result = [];
    foreach ($samples as $sample) {
        $value = (string) $sample[$key];
        $result[$value] = ($result[$value] ?? 0) + 1;
    }
    ksort($result);

    return $result;
}

function dockerContainerRunning(string $container): bool
{
    [$status, $output] = docker(['inspect', '--format', '{{.State.Running}}', $container], false);

    return $status === 0 && trim($output) === 'true';
}

function dockerContainerAutoRemove(string $container): bool
{
    [$status, $output] = docker(['inspect', '--format', '{{.HostConfig.AutoRemove}}', $container], false);

    return $status !== 0 || trim($output) !== 'false';
}

/** @param array<int,string> $arguments @return array{int,string} */
function docker(array $arguments, bool $mustSucceed = true): array
{
    $command = ['docker', ...$arguments];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start Docker command.');
    }
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($mustSucceed && $status !== 0) {
        throw new RuntimeException('Docker command failed: '.trim($output));
    }

    return [$status, $output];
}

/** @param array<string,mixed> $result */
function writeJson(string $path, array $result): void
{
    $directory = dirname($path);
    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create evidence directory: {$directory}");
    }
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException("Unable to write evidence: {$path}");
    }
}
