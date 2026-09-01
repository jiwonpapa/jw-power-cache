#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * JW PowerCache mixed-route HTTP load runner.
 *
 * Example:
 * php tool/run-mixed-load.php \
 *   --base-url=http://127.0.0.1:18087 \
 *   --label=active-c4 \
 *   --concurrency=4 \
 *   --rps=32 \
 *   --duration=60 \
 *   --max-requests=0 \
 *   --warmup=5 \
 *   --route=page=/api/modules/sirsoft-page/pages/about \
 *   --route=category-list=/api/modules/sirsoft-ecommerce/categories \
 *   --route=category-detail=/api/modules/sirsoft-ecommerce/categories/clothing \
 *   --route=board=/api/modules/sirsoft-board/boards/free/posts \
 *   --output=docs/benchmark/evidence/local/active-c4.json
 */
$options = getopt('', [
    'base-url:',
    'label:',
    'concurrency:',
    'rps::',
    'duration:',
    'max-requests::',
    'warmup::',
    'timeout::',
    'route:',
    'output:',
]);

try {
    $config = parseConfiguration($options);
    warmRoutes($config);
    $result = runLoad($config);
    writeResult($config['output'], $result);

    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    exit(($result['summary']['error_rate'] ?? 1.0) <= 0.001 ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'mixed-load: '.$e->getMessage().PHP_EOL);
    exit(2);
}

/** @return array{base_url:string,label:string,concurrency:int,rps:int,duration:int,max_requests:int,warmup:int,timeout:int,routes:array<string,string>,output:string} */
function parseConfiguration(array $options): array
{
    $baseUrl = rtrim((string) ($options['base-url'] ?? ''), '/');
    $label = trim((string) ($options['label'] ?? 'mixed-load'));
    $concurrency = filter_var($options['concurrency'] ?? null, FILTER_VALIDATE_INT);
    $rps = filter_var($options['rps'] ?? 0, FILTER_VALIDATE_INT);
    $duration = filter_var($options['duration'] ?? null, FILTER_VALIDATE_INT);
    $maxRequests = filter_var($options['max-requests'] ?? 0, FILTER_VALIDATE_INT);
    $warmup = filter_var($options['warmup'] ?? 5, FILTER_VALIDATE_INT);
    $timeout = filter_var($options['timeout'] ?? 10, FILTER_VALIDATE_INT);
    $output = trim((string) ($options['output'] ?? ''));

    if ($baseUrl === '' || ! preg_match('#^https?://#', $baseUrl)) {
        throw new InvalidArgumentException('--base-url must be an http(s) URL.');
    }
    if ($label === '') {
        throw new InvalidArgumentException('--label must not be empty.');
    }
    if ($concurrency === false || $concurrency < 1 || $concurrency > 256) {
        throw new InvalidArgumentException('--concurrency must be between 1 and 256.');
    }
    if ($rps === false || $rps < 0 || $rps > 10000) {
        throw new InvalidArgumentException('--rps must be between 0 (unlimited) and 10000.');
    }
    if ($duration === false || $duration < 1 || $duration > 3600) {
        throw new InvalidArgumentException('--duration must be between 1 and 3600 seconds.');
    }
    if ($maxRequests === false || $maxRequests < 0 || $maxRequests > 10_000_000) {
        throw new InvalidArgumentException('--max-requests must be between 0 (duration only) and 10000000.');
    }
    if ($warmup === false || $warmup < 0 || $warmup > 300) {
        throw new InvalidArgumentException('--warmup must be between 0 and 300 seconds.');
    }
    if ($timeout === false || $timeout < 1 || $timeout > 120) {
        throw new InvalidArgumentException('--timeout must be between 1 and 120 seconds.');
    }
    if ($output === '') {
        throw new InvalidArgumentException('--output is required.');
    }

    $routeOptions = $options['route'] ?? [];
    if (is_string($routeOptions)) {
        $routeOptions = [$routeOptions];
    }
    if (! is_array($routeOptions) || $routeOptions === []) {
        throw new InvalidArgumentException('At least one --route=name=/absolute/path is required.');
    }

    $routes = [];
    foreach ($routeOptions as $routeOption) {
        if (! is_string($routeOption) || ! str_contains($routeOption, '=')) {
            throw new InvalidArgumentException('Each route must use --route=name=/absolute/path.');
        }
        [$name, $uri] = array_map('trim', explode('=', $routeOption, 2));
        if ($name === '' || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid route name: {$name}");
        }
        if (! str_starts_with($uri, '/') || str_starts_with($uri, '//')) {
            throw new InvalidArgumentException("Route {$name} must be an absolute URL path.");
        }
        $routes[$name] = $baseUrl.$uri;
    }

    return [
        'base_url' => $baseUrl,
        'label' => $label,
        'concurrency' => $concurrency,
        'rps' => $rps,
        'duration' => $duration,
        'max_requests' => $maxRequests,
        'warmup' => $warmup,
        'timeout' => $timeout,
        'routes' => $routes,
        'output' => $output,
    ];
}

/** @param array{routes:array<string,string>,warmup:int,timeout:int} $config */
function warmRoutes(array $config): void
{
    if ($config['warmup'] === 0) {
        return;
    }

    $startedAt = microtime(true);
    foreach ($config['routes'] as $url) {
        $handle = createHandle($url, $config['timeout']);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        unset($handle);

        if ($status < 200 || $status >= 300 || $error !== '') {
            throw new RuntimeException("Warm-up failed for {$url}: HTTP {$status} {$error}");
        }
    }

    $remainingMicroseconds = (int) (($config['warmup'] - (microtime(true) - $startedAt)) * 1_000_000);
    if ($remainingMicroseconds > 0) {
        usleep($remainingMicroseconds);
    }
}

/**
 * @param  array{base_url:string,label:string,concurrency:int,rps:int,duration:int,max_requests:int,warmup:int,timeout:int,routes:array<string,string>}  $config
 * @return array<string,mixed>
 */
function runLoad(array $config): array
{
    $multi = curl_multi_init();
    $routeNames = array_keys($config['routes']);
    $startedAt = microtime(true);
    $deadline = $startedAt + $config['duration'];
    $sequence = 0;
    $active = [];
    $samples = [];
    $launchInterval = $config['rps'] > 0 ? 1 / $config['rps'] : 0.0;
    $nextLaunchAt = $startedAt;

    $enqueue = static function () use (&$sequence, &$active, $routeNames, $config, $multi): void {
        $routeName = $routeNames[$sequence % count($routeNames)];
        $handle = createHandle($config['routes'][$routeName], $config['timeout']);
        $id = spl_object_id($handle);
        $active[$id] = ['handle' => $handle, 'route' => $routeName];
        $sequence++;
        curl_multi_add_handle($multi, $handle);
    };

    if ($config['rps'] === 0) {
        $initial = $config['max_requests'] > 0
            ? min($config['concurrency'], $config['max_requests'])
            : $config['concurrency'];
        for ($i = 0; $i < $initial; $i++) {
            $enqueue();
        }
    }

    do {
        if ($config['rps'] > 0) {
            $now = microtime(true);
            while (count($active) < $config['concurrency']
                && $nextLaunchAt <= $now
                && $nextLaunchAt < $deadline
                && ($config['max_requests'] === 0 || $sequence < $config['max_requests'])) {
                $enqueue();
                $nextLaunchAt += $launchInterval;
            }
        }

        do {
            $status = curl_multi_exec($multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        if ($status !== CURLM_OK) {
            throw new RuntimeException('curl_multi_exec failed: '.curl_multi_strerror($status));
        }

        while (($info = curl_multi_info_read($multi)) !== false) {
            $handle = $info['handle'];
            $id = spl_object_id($handle);
            $meta = $active[$id] ?? null;
            $payload = curl_multi_getcontent($handle);
            $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
            $headers = substr($payload, 0, $headerSize);
            $body = substr($payload, $headerSize);
            $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($handle);

            $samples[] = [
                'route' => $meta['route'] ?? 'unknown',
                'latency_ms' => (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000,
                'status' => $httpStatus,
                'bytes' => strlen($body),
                'cache' => responseHeader($headers, 'X-JW-Power-Cache') ?? 'missing',
                'checksum' => hash('sha256', $body),
                'error' => $curlError !== '' ? $curlError : ($info['result'] === CURLE_OK ? null : curl_strerror($info['result'])),
            ];

            curl_multi_remove_handle($multi, $handle);
            unset($active[$id]);
            unset($handle);

            if ($config['rps'] === 0
                && microtime(true) < $deadline
                && ($config['max_requests'] === 0 || $sequence < $config['max_requests'])) {
                $enqueue();
            }
        }

        if ($running > 0) {
            $selectTimeout = 0.2;
            if ($config['rps'] > 0 && count($active) < $config['concurrency'] && $nextLaunchAt < $deadline) {
                $selectTimeout = max(0.001, min($selectTimeout, $nextLaunchAt - microtime(true)));
            }
            curl_multi_select($multi, $selectTimeout);
        } elseif ($config['rps'] > 0
            && $nextLaunchAt < $deadline
            && ($config['max_requests'] === 0 || $sequence < $config['max_requests'])) {
            $sleepMicroseconds = (int) max(1_000, min(200_000, ($nextLaunchAt - microtime(true)) * 1_000_000));
            usleep($sleepMicroseconds);
        }
    } while ($running > 0 || $active !== [] || (
        $config['rps'] > 0
        && $nextLaunchAt < $deadline
        && ($config['max_requests'] === 0 || $sequence < $config['max_requests'])
    ));

    curl_multi_close($multi);
    $finishedAt = microtime(true);

    return summarize($config, $samples, $startedAt, $finishedAt);
}

function createHandle(string $url, int $timeout): CurlHandle
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: ko',
            'User-Agent: JW-PowerCache-Beta-Load/1.0',
        ],
    ]);

    return $handle;
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

/**
 * @param  array<string,mixed>  $config
 * @param  array<int,array<string,mixed>>  $samples
 * @return array<string,mixed>
 */
function summarize(array $config, array $samples, float $startedAt, float $finishedAt): array
{
    $elapsed = max(0.001, $finishedAt - $startedAt);
    $errors = array_filter($samples, static fn (array $sample): bool => $sample['error'] !== null || $sample['status'] < 200 || $sample['status'] >= 300
    );

    return [
        'schema_version' => 2,
        'label' => $config['label'],
        'started_at' => gmdate(DATE_ATOM, (int) $startedAt),
        'finished_at' => gmdate(DATE_ATOM, (int) $finishedAt),
        'base_url' => $config['base_url'],
        'concurrency' => $config['concurrency'],
        'target_requests_per_second' => $config['rps'] > 0 ? $config['rps'] : null,
        'configured_duration_seconds' => $config['duration'],
        'configured_max_requests' => $config['max_requests'] > 0 ? $config['max_requests'] : null,
        'warmup_seconds' => $config['warmup'],
        'routes' => array_keys($config['routes']),
        'summary' => summarizeSamples($samples, $elapsed, count($errors)),
        'per_route' => array_map(
            static fn (string $route): array => summarizeSamples(
                array_values(array_filter($samples, static fn (array $sample): bool => $sample['route'] === $route)),
                $elapsed,
            ),
            array_combine(array_keys($config['routes']), array_keys($config['routes'])),
        ),
    ];
}

/** @param array<int,array<string,mixed>> $samples @return array<string,mixed> */
function summarizeSamples(array $samples, float $elapsed, ?int $knownErrors = null): array
{
    $count = count($samples);
    $errors = $knownErrors ?? count(array_filter($samples, static fn (array $sample): bool => $sample['error'] !== null || $sample['status'] < 200 || $sample['status'] >= 300
    ));
    $latencies = array_map(static fn (array $sample): float => $sample['latency_ms'], $samples);
    sort($latencies, SORT_NUMERIC);

    return [
        'requests' => $count,
        'elapsed_seconds' => round($elapsed, 3),
        'requests_per_second' => round($count / $elapsed, 2),
        'errors' => $errors,
        'error_rate' => $count > 0 ? round($errors / $count, 6) : 1.0,
        'p50_ms' => percentile($latencies, 0.50),
        'p95_ms' => percentile($latencies, 0.95),
        'p99_ms' => percentile($latencies, 0.99),
        'status_counts' => counts($samples, 'status'),
        'cache_counts' => counts($samples, 'cache'),
        'body_checksum_cardinality' => count(array_unique(array_column($samples, 'checksum'))),
        'body_checksum_counts' => counts($samples, 'checksum'),
    ];
}

/** @param array<int,float> $sorted */
function percentile(array $sorted, float $percentile): ?float
{
    if ($sorted === []) {
        return null;
    }

    $rank = (int) ceil($percentile * count($sorted)) - 1;

    return round($sorted[max(0, min($rank, count($sorted) - 1))], 3);
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

/** @param array<string,mixed> $result */
function writeResult(string $output, array $result): void
{
    $directory = dirname($output);
    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create output directory: {$directory}");
    }

    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    if (file_put_contents($output, $json) === false) {
        throw new RuntimeException("Unable to write output: {$output}");
    }
}
