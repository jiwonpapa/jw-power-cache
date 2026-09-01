#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: tool/summarize-benchmark-matrix.php <evidence-dir> [--route=name] [--output=file]\n");
    exit(2);
}

$directory = rtrim($argv[1], DIRECTORY_SEPARATOR);
$route = null;
$output = null;
foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--route=')) {
        $route = substr($argument, strlen('--route='));
    } elseif (str_starts_with($argument, '--output=')) {
        $output = substr($argument, strlen('--output='));
    } else {
        fwrite(STDERR, "Unknown argument: {$argument}\n");
        exit(2);
    }
}

try {
    $summary = summarizeMatrix($directory, $route);
    $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    if ($output !== null) {
        if (file_put_contents($output, $json) === false) {
            throw new RuntimeException("Unable to write matrix summary: {$output}");
        }
    }
    fwrite(STDOUT, $json);
    exit($summary['pass'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'benchmark-summary: '.$e->getMessage().PHP_EOL);
    exit(2);
}

/** @return array<string,mixed> */
function summarizeMatrix(string $directory, ?string $route): array
{
    if (! is_dir($directory)) {
        throw new RuntimeException("Evidence directory not found: {$directory}");
    }

    $groups = [];
    foreach (glob($directory.'/run-*-*-c*.json') ?: [] as $path) {
        if (preg_match('/\/run-(\d+)-(bypass|active)-c(\d+)\.json$/', $path, $matches) !== 1) {
            continue;
        }
        $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $metrics = $route === null
            ? ($document['summary'] ?? null)
            : ($document['per_route'][$route] ?? null);
        if (! is_array($metrics)) {
            throw new RuntimeException("Missing metrics in {$path}");
        }
        foreach (['p95_ms', 'requests_per_second', 'error_rate'] as $field) {
            if (! isset($metrics[$field]) || ! is_numeric($metrics[$field])) {
                throw new RuntimeException("Missing numeric {$field} in {$path}");
            }
        }

        $run = (int) $matches[1];
        $mode = $matches[2];
        $concurrency = (int) $matches[3];
        $groups[$concurrency][$mode][$run] = [
            'p95_ms' => (float) $metrics['p95_ms'],
            'requests_per_second' => (float) $metrics['requests_per_second'],
            'error_rate' => (float) $metrics['error_rate'],
            'target_rps' => isset($document['target_requests_per_second']) && is_numeric($document['target_requests_per_second'])
                ? (float) $document['target_requests_per_second']
                : null,
            'checksum_signature' => checksumSignature($document, $route, $path),
        ];
    }

    if ($groups === []) {
        throw new RuntimeException('No benchmark run files found.');
    }

    ksort($groups, SORT_NUMERIC);
    $results = [];
    $allPass = true;
    $gateTypes = [];
    foreach ($groups as $concurrency => $modes) {
        $bypass = $modes['bypass'] ?? [];
        $active = $modes['active'] ?? [];
        ksort($bypass, SORT_NUMERIC);
        ksort($active, SORT_NUMERIC);
        if ($bypass === [] || array_keys($bypass) !== array_keys($active)) {
            throw new RuntimeException("Concurrency {$concurrency} has incomplete or unmatched mode runs.");
        }

        $targetRates = array_values(array_unique(array_map(
            static fn (array $sample): string => $sample['target_rps'] === null ? 'uncapped' : (string) $sample['target_rps'],
            array_merge(array_values($bypass), array_values($active)),
        )));
        if (count($targetRates) !== 1) {
            throw new RuntimeException("Concurrency {$concurrency} mixes capped and uncapped runs.");
        }
        $capped = $targetRates[0] !== 'uncapped';
        $gateType = $capped ? 'capped_endurance' : 'uncapped_performance';
        $gateTypes[$gateType] = true;

        $before = medianMetrics($bypass);
        $after = medianMetrics($active);
        if ($before['p95_ms'] <= 0.0 || $before['requests_per_second'] <= 0.0) {
            throw new RuntimeException("Concurrency {$concurrency} has an invalid bypass baseline.");
        }
        $latencyImprovement = ($before['p95_ms'] - $after['p95_ms']) / $before['p95_ms'] * 100;
        $throughputImprovement = ($after['requests_per_second'] - $before['requests_per_second']) / $before['requests_per_second'] * 100;
        $latencyPass = $latencyImprovement >= 20.0;
        $throughputPass = $capped ? null : $throughputImprovement >= 20.0;
        $errorPass = $after['error_rate'] <= 0.001;
        $checksumPass = true;
        foreach (array_keys($bypass) as $run) {
            if ($bypass[$run]['checksum_signature'] !== $active[$run]['checksum_signature']) {
                $checksumPass = false;
                break;
            }
        }
        $pass = $latencyPass && $errorPass && $checksumPass && ($throughputPass ?? true);
        $allPass = $allPass && $pass;

        $results[(string) $concurrency] = [
            'pass' => $pass,
            'gate_type' => $gateType,
            'runs' => count($bypass),
            'bypass_median' => $before,
            'active_median' => $after,
            'p95_improvement_percent' => round($latencyImprovement, 1),
            'throughput_improvement_percent' => $capped ? null : round($throughputImprovement, 1),
            'checks' => [
                'p95' => $latencyPass,
                'throughput' => $throughputPass,
                'error_rate' => $errorPass,
                'response_checksum' => $checksumPass,
            ],
        ];
    }

    return [
        'schema_version' => 2,
        'pass' => $allPass,
        'gate_types' => array_keys($gateTypes),
        'route' => $route ?? 'mixed',
        'results' => $results,
        'targets' => [
            'p95_improvement_percent' => 20.0,
            'throughput_improvement_percent' => 20.0,
            'max_error_rate' => 0.001,
        ],
    ];
}

/** @return array<string,array<int,string>> */
function checksumSignature(array $document, ?string $route, string $path): array
{
    $routes = $route === null
        ? ($document['per_route'] ?? null)
        : [$route => $document['per_route'][$route] ?? null];
    if (! is_array($routes)) {
        throw new RuntimeException("{$path} has no per-route checksum evidence.");
    }

    $signature = [];
    foreach ($routes as $name => $metrics) {
        $counts = is_array($metrics) ? ($metrics['body_checksum_counts'] ?? null) : null;
        if (! is_array($counts) || $counts === []) {
            throw new RuntimeException("{$path} has no checksum evidence for route {$name}.");
        }
        $hashes = array_keys($counts);
        sort($hashes, SORT_STRING);
        $signature[(string) $name] = $hashes;
    }
    ksort($signature, SORT_STRING);

    return $signature;
}

/** @param array<int,array<string,mixed>> $runs @return array<string,float> */
function medianMetrics(array $runs): array
{
    return [
        'p95_ms' => median(array_column($runs, 'p95_ms')),
        'requests_per_second' => median(array_column($runs, 'requests_per_second')),
        'error_rate' => median(array_column($runs, 'error_rate')),
    ];
}

/** @param array<int,float|int> $values */
function median(array $values): float
{
    if ($values === []) {
        throw new RuntimeException('Cannot calculate a median from no values.');
    }
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $middle = intdiv($count, 2);

    return $count % 2 === 1
        ? (float) $values[$middle]
        : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
}
