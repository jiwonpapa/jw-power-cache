#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc < 3 || $argc > 4 || ($argc === 4 && ! str_starts_with($argv[3], '--route='))) {
    fwrite(STDERR, "Usage: tool/compare-benchmarks.php <bypass.json> <active.json> [--route=name]\n");
    exit(2);
}

function loadResult(string $path, ?string $route): array
{
    if (! is_file($path) || ! is_readable($path)) {
        throw new RuntimeException("Benchmark result is not readable: {$path}");
    }
    $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $targetRps = isset($document['target_requests_per_second']) && is_numeric($document['target_requests_per_second'])
        ? (float) $document['target_requests_per_second']
        : null;
    $result = $document;
    if ($route !== null) {
        $result = $result['per_route'][$route] ?? throw new RuntimeException("{$path} has no route {$route}.");
    } elseif (isset($result['summary']) && is_array($result['summary'])) {
        $result = $result['summary'];
    }
    foreach (['p95_ms', 'requests_per_second', 'error_rate'] as $field) {
        if (! isset($result[$field]) || ! is_numeric($result[$field])) {
            throw new RuntimeException("{$path} has no numeric {$field} field.");
        }
    }

    return [
        'metrics' => $result,
        'target_rps' => $targetRps,
        'checksum_signature' => checksumSignature($document, $route, $path),
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

$route = $argc === 4 ? substr($argv[3], strlen('--route=')) : null;
$beforeResult = loadResult($argv[1], $route);
$afterResult = loadResult($argv[2], $route);
$before = $beforeResult['metrics'];
$after = $afterResult['metrics'];
if ((float) $before['p95_ms'] <= 0.0 || (float) $before['requests_per_second'] <= 0.0) {
    throw new RuntimeException('Bypass p95 and requests_per_second must be greater than zero.');
}
$latencyImprovement = ((float) $before['p95_ms'] - (float) $after['p95_ms']) / (float) $before['p95_ms'] * 100;
$throughputImprovement = ((float) $after['requests_per_second'] - (float) $before['requests_per_second']) / (float) $before['requests_per_second'] * 100;
$capped = $beforeResult['target_rps'] !== null || $afterResult['target_rps'] !== null;
if ($beforeResult['target_rps'] !== $afterResult['target_rps']) {
    throw new RuntimeException('Bypass and active target request rates do not match.');
}
$latencyPass = $latencyImprovement >= 20.0;
$throughputPass = $capped ? null : $throughputImprovement >= 20.0;
$errorPass = (float) $after['error_rate'] <= 0.001;
$checksumPass = $beforeResult['checksum_signature'] === $afterResult['checksum_signature'];
$pass = $latencyPass && $errorPass && $checksumPass && ($throughputPass ?? true);

$summary = [
    'pass' => $pass,
    'gate_type' => $capped ? 'capped_endurance' : 'uncapped_performance',
    'route' => $route ?? 'mixed',
    'bypass' => [
        'p95_ms' => (float) $before['p95_ms'],
        'requests_per_second' => (float) $before['requests_per_second'],
        'error_rate' => (float) $before['error_rate'],
    ],
    'active' => [
        'p95_ms' => (float) $after['p95_ms'],
        'requests_per_second' => (float) $after['requests_per_second'],
        'error_rate' => (float) $after['error_rate'],
    ],
    'p95_improvement_percent' => round($latencyImprovement, 1),
    'throughput_improvement_percent' => $capped ? null : round($throughputImprovement, 1),
    'active_error_rate' => (float) $after['error_rate'],
    'checks' => [
        'p95' => $latencyPass,
        'throughput' => $throughputPass,
        'error_rate' => $errorPass,
        'response_checksum' => $checksumPass,
    ],
    'targets' => [
        'p95_improvement_percent' => 20.0,
        'throughput_improvement_percent' => 20.0,
        'max_error_rate' => 0.001,
    ],
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($pass ? 0 : 1);
