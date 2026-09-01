<?php

namespace Plugins\Jw\PowerCache\Tests\Integration;

use App\Extension\Cache\PluginCacheDriver;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\DB;
use Plugins\Jw\PowerCache\Invalidation\InvalidationApplier;
use Plugins\Jw\PowerCache\Invalidation\OutboxReconciler;
use Plugins\Jw\PowerCache\Runtime\PowerCacheSettings;
use Plugins\Jw\PowerCache\Runtime\RecoveryBarrier;
use Plugins\Jw\PowerCache\Store\ControlPlaneMissingException;
use Plugins\Jw\PowerCache\Store\G7PowerCacheStore;
use Plugins\Jw\PowerCache\Tests\Support\PowerCacheTestCase;

final class G7CacheControlPlaneTest extends PowerCacheTestCase
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

            $connection->command('del', [$prefix.'g7:plugin.jw-power_cache:generation:'.hash('sha256', 'site')]);
            try {
                $store->generations(['site']);
                self::fail('Missing Redis generation must block the HIT path.');
            } catch (ControlPlaneMissingException) {
                self::assertNotNull($store->getResponse('old-response'));
            }

            $settings = new PowerCacheSettings(['automatic_recovery' => true]);
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
            self::assertNotNull($store->getResponse('old-response'));
        } finally {
            $this->deletePrefix($connection, $prefix);
            $redis->purge('jwpc_test');
        }
    }

    public function test_database_fill_lock_is_exclusive_across_standard_cache_instances(): void
    {
        [$first, $redis, $connection, $prefix] = $this->redisStore();
        $second = new G7PowerCacheStore(new PluginCacheDriver('jw-power_cache', 'redis'));

        try {
            $owner = $first->acquireLock('same-request', 5);
            self::assertNotNull($owner);
            self::assertNull($second->acquireLock('same-request', 5));
            self::assertTrue($owner->release());

            $nextOwner = $second->acquireLock('same-request', 5);
            self::assertNotNull($nextOwner);
            self::assertTrue($nextOwner->release());
        } finally {
            $this->deletePrefix($connection, $prefix);
            $redis->purge('jwpc_test');
        }
    }

    public function test_probe_uses_the_g7_selected_redis_store(): void
    {
        [$store, $redis, $connection, $prefix] = $this->redisStore();

        try {
            $probe = $store->probe();

            self::assertTrue($probe['ok']);
            self::assertSame('redis', $probe['driver']);
            self::assertSame(['ok', 'driver'], array_keys($probe));
        } finally {
            $this->deletePrefix($connection, $prefix);
            $redis->purge('jwpc_test');
        }
    }

    public function test_expired_database_lease_can_be_replaced_without_old_owner_releasing_new_owner(): void
    {
        $first = $this->arrayStore();
        $second = new G7PowerCacheStore(new PluginCacheDriver('jw-power_cache', 'array'));
        $oldOwner = $first->acquireLock('expired-lock', 1);
        self::assertNotNull($oldOwner);

        $stateKey = 'lock:'.substr(hash('sha256', 'expired-lock'), 0, 59);
        DB::table('jw_power_cache_state')->where('state_key', $stateKey)->update([
            'state_value' => json_encode([
                'owner' => $oldOwner->owner(),
                'expires_at' => time() - 1,
            ], JSON_THROW_ON_ERROR),
        ]);

        $newOwner = $second->acquireLock('expired-lock', 5);
        self::assertNotNull($newOwner);
        self::assertFalse($oldOwner->release());
        self::assertTrue($newOwner->release());
    }

    /** @return array{G7PowerCacheStore, RedisManager, mixed, string} */
    private function redisStore(): array
    {
        $url = getenv('JW_POWER_CACHE_TEST_REDIS_URL');
        if (! is_string($url) || $url === '') {
            self::markTestSkipped('JW_POWER_CACHE_TEST_REDIS_URL is not configured.');
        }

        $redis = new RedisManager($this->app, 'predis', [
            'jwpc_test' => ['url' => $url],
        ]);
        $prefix = 'jwpc-it:'.bin2hex(random_bytes(8)).':';
        $this->app->instance('redis', $redis);
        config([
            'cache.default' => 'redis',
            'cache.stores.redis' => [
                'driver' => 'redis',
                'connection' => 'jwpc_test',
                'lock_connection' => 'jwpc_test',
                'prefix' => $prefix,
            ],
        ]);

        return [
            new G7PowerCacheStore(new PluginCacheDriver('jw-power_cache', 'redis')),
            $redis,
            $redis->connection('jwpc_test'),
            $prefix,
        ];
    }

    private function deletePrefix(mixed $connection, string $prefix): void
    {
        $keys = $connection->command('keys', [$prefix.'*']);
        if (is_array($keys) && $keys !== []) {
            $connection->command('del', array_values($keys));
        }
    }
}
