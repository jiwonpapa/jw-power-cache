<?php

namespace Plugins\Jw\PowerCache\Store;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Plugins\Jw\PowerCache\Contracts\PowerCacheStoreInterface;
use Plugins\Jw\PowerCache\Runtime\ControlBarrierState;
use Plugins\Jw\PowerCache\Runtime\RuntimeSnapshot;
use RuntimeException;
use Throwable;

final class LaravelPowerCacheStore implements PowerCacheStoreInterface
{
    private const EMERGENCY_DIRTY_KEY = 'barrier:emergency-dirty';

    private const RUNTIME_SNAPSHOT_KEY = 'barrier:runtime-snapshot';

    public function __construct(
        private readonly Repository $cache,
        private readonly string $driver,
        private readonly ?string $filePath = null,
        private readonly ?string $fileGcSafeRoot = null,
    ) {}

    public function getResponse(string $requestKey): ?array
    {
        $entry = $this->cache->get($this->responseKey($requestKey));

        return is_array($entry) ? $entry : null;
    }

    public function putResponse(string $requestKey, array $entry, int $retentionSeconds): void
    {
        if (! $this->cache->put($this->responseKey($requestKey), $entry, $retentionSeconds)) {
            throw new RuntimeException('JW PowerCache response store write failed.');
        }
    }

    public function generations(array $scopes): array
    {
        $scopes = $this->normalizeScopes($scopes);
        if ($scopes === []) {
            return [];
        }

        $keys = [];
        foreach ($scopes as $scope) {
            $keys[$scope] = $this->generationKey($scope);
        }

        $values = $this->cache->many(array_values($keys));
        $result = [];
        $missing = [];
        foreach ($keys as $scope => $key) {
            $value = $values[$key] ?? null;
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                $missing[] = $scope;

                continue;
            }
            $result[$scope] = max(0, (int) $value);
        }

        if ($missing !== []) {
            throw new ControlPlaneMissingException($missing);
        }

        return $result;
    }

    public function advanceGenerations(array $scopes, int $eventId): void
    {
        $scopes = $this->normalizeScopes($scopes);
        if ($scopes === []) {
            return;
        }

        $lock = $this->acquireLock('generation-writer', 10);
        if ($lock === null) {
            throw new RuntimeException('JW PowerCache generation writer lock is busy.');
        }

        try {
            foreach ($scopes as $scope) {
                $key = $this->generationKey($scope);
                $current = max(0, (int) $this->cache->get($key, 0));
                if ($eventId > $current && ! $this->cache->forever($key, $eventId)) {
                    throw new RuntimeException("JW PowerCache generation write failed: {$scope}");
                }
            }
        } finally {
            $lock->release();
        }
    }

    public function acquireLock(string $name, int $leaseSeconds): ?Lock
    {
        $lock = $this->cache->lock($this->lockKey($name), max(1, $leaseSeconds));

        return $lock->get() ? $lock : null;
    }

    public function controlBarrier(): ?ControlBarrierState
    {
        $state = $this->cache->get(self::EMERGENCY_DIRTY_KEY);
        if (! is_array($state)
            || ($state['version'] ?? null) !== 1
            || ! is_bool($state['dirty'] ?? null)
            || ! is_string($state['token'] ?? null)
            || ($state['token'] ?? '') === ''
            || ! is_numeric($state['event_id'] ?? null)
            || ! is_string($state['reason'] ?? null)
            || ! is_string($state['updated_at'] ?? null)) {
            return null;
        }

        return new ControlBarrierState(
            $state['dirty'],
            $state['token'],
            max(0, (int) $state['event_id']),
            $state['reason'],
            $state['updated_at'],
        );
    }

    public function markEmergencyDirty(string $reason, ?string $token = null, int $eventId = 0): string
    {
        $token ??= 'dirty:'.bin2hex(random_bytes(16));
        $lock = $this->controlWriterLock();

        try {
            $current = $this->controlBarrier();
            if ($current?->dirty === true
                && ($eventId === 0 || $current->eventId > max(0, $eventId))) {
                return $token;
            }

            $this->putControlBarrier(true, $token, $eventId, $reason);

            return $token;
        } finally {
            $lock->release();
        }
    }

    public function clearEmergencyDirty(?string $expectedToken = null): bool
    {
        $lock = $this->controlWriterLock();

        try {
            $current = $this->controlBarrier();
            if ($current === null
                || ($expectedToken !== null && ! hash_equals($expectedToken, $current->token))) {
                return false;
            }

            $this->putControlBarrier(
                false,
                'clean:'.bin2hex(random_bytes(16)),
                $current->eventId,
                'clean',
            );

            return true;
        } finally {
            $lock->release();
        }
    }

    public function resetControlPlane(RuntimeSnapshot $snapshot, array $scopes, string $expectedToken): bool
    {
        $lock = $this->controlWriterLock();

        try {
            $current = $this->controlBarrier();
            if ($current === null
                || ! $current->dirty
                || ! hash_equals($expectedToken, $current->token)) {
                return false;
            }

            foreach ($this->normalizeScopes($scopes) as $scope) {
                if (! $this->cache->forever($this->generationKey($scope), 0)) {
                    throw new RuntimeException("JW PowerCache generation reset failed: {$scope}");
                }
            }

            $this->putRuntimeSnapshot($snapshot);
            $this->putControlBarrier(
                false,
                'clean:'.bin2hex(random_bytes(16)),
                0,
                'control_plane_reset',
            );

            return true;
        } finally {
            $lock->release();
        }
    }

    public function runtimeSnapshot(): ?RuntimeSnapshot
    {
        $state = $this->cache->get(self::RUNTIME_SNAPSHOT_KEY);
        if (! is_array($state)
            || ! is_string($state['site_id'] ?? null)
            || ! is_string($state['runtime_epoch'] ?? null)
            || ! is_numeric($state['dirty_event_id'] ?? null)) {
            return null;
        }

        return new RuntimeSnapshot(
            $state['site_id'],
            $state['runtime_epoch'],
            max(0, (int) $state['dirty_event_id']),
        );
    }

    public function putRuntimeSnapshot(RuntimeSnapshot $snapshot): void
    {
        if (! $this->cache->forever(self::RUNTIME_SNAPSHOT_KEY, [
            'site_id' => $snapshot->siteId,
            'runtime_epoch' => $snapshot->runtimeEpoch,
            'dirty_event_id' => $snapshot->dirtyEventId,
        ])) {
            throw new RuntimeException('JW PowerCache runtime snapshot write failed.');
        }
    }

    public function forgetRuntimeSnapshot(): void
    {
        $this->cache->forget(self::RUNTIME_SNAPSHOT_KEY);
    }

    public function incrementMetric(string $metric): void
    {
        $metric = preg_replace('/[^a-z0-9_.:-]/i', '_', $metric) ?: 'unknown';
        $key = 'metric:'.gmdate('YmdH').':'.$metric;
        $this->cache->add($key, 0, 691200);
        $this->cache->increment($key);
    }

    public function probe(): array
    {
        $key = 'probe:'.bin2hex(random_bytes(8));
        $value = bin2hex(random_bytes(12));
        $written = $this->cache->put($key, $value, 10);
        $readBack = $this->cache->get($key);
        $this->cache->forget($key);

        $result = [
            'ok' => $written && hash_equals($value, (string) $readBack),
            'driver' => $this->driver,
        ];

        if ($this->driver === 'redis' && $this->cache->getStore() instanceof RedisStore) {
            $result['redis'] = $this->redisDiagnostics($this->cache->getStore());
        }

        return $result;
    }

    public function garbageCollect(): array
    {
        if ($this->driver !== 'file' || $this->filePath === null) {
            return ['supported' => false, 'deleted' => 0];
        }

        return (new FileCacheGarbageCollector)->collect(
            $this->filePath,
            safeRoot: $this->fileGcSafeRoot,
        );
    }

    public function driverName(): string
    {
        return $this->driver;
    }

    private function responseKey(string $requestKey): string
    {
        return 'response:'.$requestKey;
    }

    private function generationKey(string $scope): string
    {
        return 'generation:'.hash('sha256', $scope);
    }

    private function lockKey(string $name): string
    {
        return 'lock:'.hash('sha256', $name);
    }

    private function controlWriterLock(): Lock
    {
        $lock = $this->acquireLock('control-plane-writer', 15);
        if ($lock === null) {
            throw new RuntimeException('JW PowerCache control-plane writer lock is busy.');
        }

        return $lock;
    }

    private function putControlBarrier(bool $dirty, string $token, int $eventId, string $reason): void
    {
        if (! $this->cache->forever(self::EMERGENCY_DIRTY_KEY, [
            'version' => 1,
            'dirty' => $dirty,
            'token' => $token,
            'event_id' => max(0, $eventId),
            'reason' => mb_substr($reason, 0, 500),
            'updated_at' => gmdate(DATE_ATOM),
        ])) {
            throw new RuntimeException('JW PowerCache control barrier write failed.');
        }
    }

    /** @return array<string, int|string|null> */
    private function redisDiagnostics(RedisStore $store): array
    {
        try {
            $connection = $store->connection();
            $policy = $this->redisConfigValue(
                $connection->command('config', ['GET', 'maxmemory-policy']),
                'maxmemory-policy',
            );
            $maxMemory = $this->redisConfigValue(
                $connection->command('config', ['GET', 'maxmemory']),
                'maxmemory',
            );
            $stats = $connection->command('info', ['stats']);
            $evictedKeys = null;
            if (is_array($stats)) {
                $statsSection = $stats['Stats'] ?? $stats['stats'] ?? $stats;
                if (is_array($statsSection) && is_numeric($statsSection['evicted_keys'] ?? null)) {
                    $evictedKeys = (int) $statsSection['evicted_keys'];
                }
            } elseif (is_string($stats)) {
                preg_match('/^evicted_keys:(\d+)$/m', $stats, $evicted);
                $evictedKeys = isset($evicted[1]) ? (int) $evicted[1] : null;
            }

            return [
                'maxmemory_policy' => $policy,
                'maxmemory_bytes' => $maxMemory === null ? null : max(0, (int) $maxMemory),
                'evicted_keys' => $evictedKeys,
            ];
        } catch (Throwable $e) {
            return ['diagnostics_error' => $e->getMessage()];
        }
    }

    private function redisConfigValue(mixed $result, string $name): ?string
    {
        if (! is_array($result)) {
            return null;
        }

        if (isset($result[$name]) && is_scalar($result[$name])) {
            return (string) $result[$name];
        }

        $values = array_values($result);

        return isset($values[1]) && is_scalar($values[1]) ? (string) $values[1] : null;
    }

    /** @param array<int, string> $scopes @return array<int, string> */
    private function normalizeScopes(array $scopes): array
    {
        $scopes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $scope): string => trim((string) $scope),
            $scopes,
        ))));
        sort($scopes, SORT_STRING);

        return $scopes;
    }
}
