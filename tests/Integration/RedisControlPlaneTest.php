<?php

namespace Plugins\Jw\PowerCache\Tests\Integration;

use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Redis\RedisManager;
use Plugins\Jw\PowerCache\Invalidation\InvalidationApplier;
use Plugins\Jw\PowerCache\Invalidation\OutboxReconciler;
use Plugins\Jw\PowerCache\Runtime\PowerCacheSettings;
use Plugins\Jw\PowerCache\Runtime\RecoveryBarrier;
use Plugins\Jw\PowerCache\Store\ControlPlaneMissingException;
use Plugins\Jw\PowerCache\Store\LaravelPowerCacheStore;
use Plugins\Jw\PowerCache\Tests\Support\PowerCacheTestCase;

final class RedisControlPlaneTest extends PowerCacheTestCase
{
    public function test_selective_generation_loss_rotates_epoch_without_serving_generation_zero(): void
    {
        [$store, $redis, $connection, $prefix] = $this->redisStore();
        $repository = $this->repository();
        $initialSnapshot = $repository->snapshot();

        try {
            $token = $store->markEmergencyDirty('bootstrap', 'redis-bootstrap');
            self::assertTrue($store->resetControlPlane(
                $initialSnapshot,
                (array) config('jw_power_cache.control_scopes', []),
                $token,
            ));
            $store->putResponse('old-response', [
                'version' => 1,
                'generations' => $store->generations(['site']),
            ], 3600);
            $store->advanceGenerations(['site'], 7);

            $connection->command('del', [$prefix.'generation:'.hash('sha256', 'site')]);
            try {
                $store->generations(['site']);
                self::fail('Missing Redis generation must block the HIT path.');
            } catch (ControlPlaneMissingException) {
                self::assertNotNull($store->getResponse('old-response'));
            }

            $settings = new PowerCacheSettings([
                'store_driver' => 'redis',
                'automatic_recovery' => true,
            ]);
            $applier = new InvalidationApplier($repository, $store);
            $barrier = new RecoveryBarrier(
                $repository,
                $store,
                new OutboxReconciler($repository, $store, $applier),
                $settings,
            );
            $result = $barrier->inspect(['site']);

            self::assertTrue($result->ready);
            self::assertNotNull($result->snapshot);
            self::assertNotSame($initialSnapshot->runtimeEpoch, $result->snapshot->runtimeEpoch);
            self::assertSame(['site' => 0], $store->generations(['site']));
            self::assertNotNull($store->getResponse('old-response'), 'Old payload may survive physically but is unreachable through the new epoch.');
        } finally {
            $this->deleteKeys($connection, $prefix, [
                'barrier:emergency-dirty',
                'barrier:runtime-snapshot',
                'generation:'.hash('sha256', 'site'),
                'generation:'.hash('sha256', 'page:all'),
                'generation:'.hash('sha256', 'category:tree'),
                'generation:'.hash('sha256', 'board:all'),
                'response:old-response',
            ]);
            $redis->purge('test');
        }
    }

    public function test_redis_fill_lock_is_exclusive_and_recoverable(): void
    {
        [$first, $redis, $connection, $prefix] = $this->redisStore();
        $second = new LaravelPowerCacheStore($firstCache = $this->redisRepository($redis, $prefix), 'redis');

        try {
            $owner = $first->acquireLock('same-request', 5);
            self::assertNotNull($owner);
            self::assertNull($second->acquireLock('same-request', 5));
            $owner->release();

            $nextOwner = $second->acquireLock('same-request', 5);
            self::assertNotNull($nextOwner);
            $nextOwner->release();
        } finally {
            $this->deleteKeys($connection, $prefix, [
                'lock:'.hash('sha256', 'same-request'),
            ]);
            unset($firstCache);
            $redis->purge('test');
        }
    }

    public function test_redis_probe_reports_eviction_and_memory_diagnostics(): void
    {
        [$store, $redis, $connection, $prefix] = $this->redisStore();

        try {
            $probe = $store->probe();

            self::assertTrue($probe['ok']);
            self::assertSame('redis', $probe['driver']);
            self::assertIsArray($probe['redis']);
            self::assertNotSame('', (string) ($probe['redis']['maxmemory_policy'] ?? ''));
            self::assertIsInt($probe['redis']['maxmemory_bytes']);
            self::assertIsInt($probe['redis']['evicted_keys']);
        } finally {
            $this->deleteKeys($connection, $prefix, []);
            $redis->purge('test');
        }
    }

    /** @return array{LaravelPowerCacheStore, RedisManager, mixed, string} */
    private function redisStore(): array
    {
        $url = getenv('JW_POWER_CACHE_TEST_REDIS_URL');
        if (! is_string($url) || $url === '') {
            self::markTestSkipped('JW_POWER_CACHE_TEST_REDIS_URL is not configured.');
        }

        $redis = new RedisManager($this->app, 'predis', [
            'test' => ['url' => $url],
        ]);
        $prefix = 'jwpc-it:'.bin2hex(random_bytes(8)).':';
        $repository = $this->redisRepository($redis, $prefix);

        return [
            new LaravelPowerCacheStore($repository, 'redis'),
            $redis,
            $redis->connection('test'),
            $prefix,
        ];
    }

    private function redisRepository(RedisManager $redis, string $prefix): CacheRepository
    {
        $store = new RedisStore($redis, $prefix, 'test');
        $store->setLockConnection('test');

        return new CacheRepository($store);
    }

    /** @param array<int, string> $keys */
    private function deleteKeys(mixed $connection, string $prefix, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        $connection->command('del', array_map(
            static fn (string $key): string => $prefix.$key,
            $keys,
        ));
    }
}
