#!/usr/bin/env php
<?php

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: tool/compare-benchmarks.php <bypass.json> <active.json>\n");
    exit(2);
}

function loadResult(string $path): array
{
    $result = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    foreach (['p95_ms', 'requests_per_second', 'error_rate'] as $field) {
        if (! isset($result[$field]) || ! is_numeric($result[$field])) {
            throw new RuntimeException("{$path} has no numeric {$field} field.");
        }
    }

    return $result;
}

$before = loadResult($argv[1]);
$after = loadResult($argv[2]);
$latencyImprovement = ((float) $before['p95_ms'] - (float) $after['p95_ms']) / (float) $before['p95_ms'] * 100;
$throughputImprovement = ((float) $after['requests_per_second'] - (float) $before['requests_per_second']) / (float) $before['requests_per_second'] * 100;
$pass = $latencyImprovement >= 20.0
    && $throughputImprovement >= 20.0
    && (float) $after['error_rate'] <= 0.001;

$summary = [
    'pass' => $pass,
    'p95_improvement_percent' => round($latencyImprovement, 1),
    'throughput_improvement_percent' => round($throughputImprovement, 1),
    'active_error_rate' => (float) $after['error_rate'],
    'targets' => [
        'p95_improvement_percent' => 20.0,
        'throughput_improvement_percent' => 20.0,
        'max_error_rate' => 0.001,
    ],
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($pass ? 0 : 1);
