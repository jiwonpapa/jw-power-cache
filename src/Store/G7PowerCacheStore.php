<?php

namespace Plugins\Jw\PowerCache\Store;

use App\Contracts\Extension\CacheInterface;
use Illuminate\Contracts\Cache\Lock;
use Plugins\Jw\PowerCache\Contracts\PowerCacheStoreInterface;
use Plugins\Jw\PowerCache\Runtime\ControlBarrierState;
use Plugins\Jw\PowerCache\Runtime\RuntimeSnapshot;
use RuntimeException;

final class G7PowerCacheStore implements PowerCacheStoreInterface
{
    private const EMERGENCY_DIRTY_KEY = 'barrier:emergency-dirty';

    private const RUNTIME_SNAPSHOT_KEY = 'barrier:runtime-snapshot';

    private const CONTROL_TTL_SECONDS = 315_360_000;

    public function __construct(private readonly CacheInterface $cache) {}

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
                if ($eventId > $current) {
                    $this->writeControl($key, $eventId);
                }
            }
        } finally {
            $lock->release();
        }
    }

    public function acquireLock(string $name, int $leaseSeconds): ?Lock
    {
        $lock = new DatabaseLeaseLock($name, max(1, $leaseSeconds));

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
                $this->writeControl($this->generationKey($scope), 0);
            }

            $this->putRuntimeSnapshot($snapshot);
            $this->putControlBarrier(false, 'clean:'.bin2hex(random_bytes(16)), 0, 'control_plane_reset');

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
        $this->writeControl(self::RUNTIME_SNAPSHOT_KEY, [
            'site_id' => $snapshot->siteId,
            'runtime_epoch' => $snapshot->runtimeEpoch,
            'dirty_event_id' => $snapshot->dirtyEventId,
        ]);
    }

    public function forgetRuntimeSnapshot(): void
    {
        $this->cache->forget(self::RUNTIME_SNAPSHOT_KEY);
    }

    public function incrementMetric(string $metric): void
    {
        $metric = preg_replace('/[^a-z0-9_.:-]/i', '_', $metric) ?: 'unknown';
        $key = 'metric:'.gmdate('YmdH').':'.$metric;
        $next = max(0, (int) $this->cache->get($key, 0)) + 1;
        $this->cache->put($key, $next, 691_200);
    }

    public function probe(): array
    {
        $key = 'probe:'.bin2hex(random_bytes(8));
        $value = bin2hex(random_bytes(12));
        $written = $this->cache->put($key, $value, 10);
        $readBack = $this->cache->get($key);
        $this->cache->forget($key);

        return [
            'ok' => $written && hash_equals($value, (string) $readBack),
            'driver' => $this->driverName(),
        ];
    }

    public function garbageCollect(): array
    {
        return ['supported' => false, 'deleted' => 0];
    }

    public function driverName(): string
    {
        return $this->cache->getStore();
    }

    private function responseKey(string $requestKey): string
    {
        return 'response:'.$requestKey;
    }

    private function generationKey(string $scope): string
    {
        return 'generation:'.hash('sha256', $scope);
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
        $this->writeControl(self::EMERGENCY_DIRTY_KEY, [
            'version' => 1,
            'dirty' => $dirty,
            'token' => $token,
            'event_id' => max(0, $eventId),
            'reason' => mb_substr($reason, 0, 500),
            'updated_at' => gmdate(DATE_ATOM),
        ]);
    }

    private function writeControl(string $key, mixed $value): void
    {
        if (! $this->cache->put($key, $value, self::CONTROL_TTL_SECONDS)) {
            throw new RuntimeException("JW PowerCache control write failed: {$key}");
        }
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
