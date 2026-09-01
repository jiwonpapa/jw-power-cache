<?php

namespace Plugins\Jw\PowerCache\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Plugins\Jw\PowerCache\Runtime\RuntimeSnapshot;
use Plugins\Jw\PowerCache\Store\ControlPlaneMissingException;
use Plugins\Jw\PowerCache\Store\LaravelPowerCacheStore;
use Plugins\Jw\PowerCache\Tests\Support\PowerCacheTestCase;

final class LaravelPowerCacheStoreTest extends PowerCacheTestCase
{
    public function test_generation_advance_is_monotonic_and_idempotent(): void
    {
        $store = $this->arrayStore();

        self::assertSame(['page:all' => 0, 'site' => 0], $store->generations(['site', 'page:all']));
        $store->advanceGenerations(['site', 'page:all'], 7);
        $store->advanceGenerations(['site', 'page:all'], 3);

        self::assertSame(['page:all' => 7, 'site' => 7], $store->generations(['site', 'page:all']));
    }

    public function test_response_and_emergency_barrier_round_trip(): void
    {
        $store = $this->arrayStore();
        $store->putResponse('request', ['version' => 1, 'body' => '{}'], 60);

        self::assertSame('{}', $store->getResponse('request')['body']);
        self::assertFalse($store->controlBarrier()?->dirty);
        $token = $store->markEmergencyDirty('test', 'test-event', 10);
        self::assertTrue($store->controlBarrier()?->dirty);
        self::assertFalse($store->clearEmergencyDirty('another-event'));
        self::assertTrue($store->controlBarrier()?->dirty);
        self::assertTrue($store->clearEmergencyDirty($token));
        self::assertFalse($store->controlBarrier()?->dirty);

        self::assertNotNull($store->runtimeSnapshot());
        $store->forgetRuntimeSnapshot();
        self::assertNull($store->runtimeSnapshot());
        $snapshot = new RuntimeSnapshot('site-id', 'epoch-id', 0);
        $store->putRuntimeSnapshot($snapshot);
        self::assertEquals($snapshot, $store->runtimeSnapshot());
        $store->forgetRuntimeSnapshot();
        self::assertNull($store->runtimeSnapshot());
    }

    public function test_missing_generation_is_never_interpreted_as_generation_zero(): void
    {
        $cache = new CacheRepository(new ArrayStore(true));
        $store = new LaravelPowerCacheStore($cache, 'array');
        $snapshot = $this->repository()->snapshot();
        $token = $store->markEmergencyDirty('bootstrap', 'bootstrap');
        self::assertTrue($store->resetControlPlane($snapshot, ['site'], $token));

        $oldGenerations = $store->generations(['site']);
        $store->putResponse('old-response', [
            'version' => 1,
            'generations' => $oldGenerations,
        ], 3600);
        $store->advanceGenerations(['site'], 7);
        $cache->forget('generation:'.hash('sha256', 'site'));

        $this->expectException(ControlPlaneMissingException::class);
        try {
            $store->generations(['site']);
        } finally {
            self::assertNotNull($store->getResponse('old-response'));
        }
    }

    public function test_older_event_cannot_replace_or_clear_a_newer_dirty_barrier(): void
    {
        $store = $this->arrayStore();
        $newerToken = $store->markEmergencyDirty('newer', 'event:10', 10);
        $olderToken = $store->markEmergencyDirty('older', 'event:9', 9);

        self::assertSame('event:10', $store->controlBarrier()?->token);
        self::assertSame(10, $store->controlBarrier()?->eventId);
        self::assertFalse($store->clearEmergencyDirty($olderToken));
        self::assertTrue($store->controlBarrier()?->dirty);
        self::assertTrue($store->clearEmergencyDirty($newerToken));
    }
}
