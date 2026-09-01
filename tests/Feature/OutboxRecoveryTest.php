<?php

namespace Plugins\Jw\PowerCache\Tests\Feature;

use App\Extension\Cache\PluginCacheDriver;
use Illuminate\Contracts\Cache\Lock;
use Plugins\Jw\PowerCache\Contracts\PowerCacheStoreInterface;
use Plugins\Jw\PowerCache\Invalidation\InvalidationApplier;
use Plugins\Jw\PowerCache\Invalidation\InvalidationCoordinator;
use Plugins\Jw\PowerCache\Invalidation\OutboxReconciler;
use Plugins\Jw\PowerCache\Runtime\ControlBarrierState;
use Plugins\Jw\PowerCache\Runtime\PowerCacheSettings;
use Plugins\Jw\PowerCache\Runtime\RecoveryBarrier;
use Plugins\Jw\PowerCache\Runtime\RuntimeSnapshot;
use Plugins\Jw\PowerCache\Store\G7PowerCacheStore;
use Plugins\Jw\PowerCache\Tests\Support\PowerCacheTestCase;
use RuntimeException;

final class OutboxRecoveryTest extends PowerCacheTestCase
{
    public function test_store_failure_keeps_dirty_barrier_until_idempotent_replay_succeeds(): void
    {
        $repository = $this->repository();
        $healthyStore = $this->arrayStore();
        $failedStore = new class($healthyStore) implements PowerCacheStoreInterface
        {
            public function __construct(private readonly PowerCacheStoreInterface $delegate) {}

            public function getResponse(string $requestKey): ?array
            {
                return $this->delegate->getResponse($requestKey);
            }

            public function putResponse(string $requestKey, array $entry, int $retentionSeconds): void
            {
                $this->delegate->putResponse($requestKey, $entry, $retentionSeconds);
            }

            public function generations(array $scopes): array
            {
                return $this->delegate->generations($scopes);
            }

            public function advanceGenerations(array $scopes, int $eventId): void
            {
                throw new RuntimeException('redis unavailable');
            }

            public function acquireLock(string $name, int $leaseSeconds): ?Lock
            {
                return $this->delegate->acquireLock($name, $leaseSeconds);
            }

            public function controlBarrier(): ?ControlBarrierState
            {
                return $this->delegate->controlBarrier();
            }

            public function markEmergencyDirty(string $reason, ?string $token = null, int $eventId = 0): string
            {
                return $this->delegate->markEmergencyDirty($reason, $token, $eventId);
            }

            public function clearEmergencyDirty(?string $expectedToken = null): bool
            {
                return $this->delegate->clearEmergencyDirty($expectedToken);
            }

            public function resetControlPlane(RuntimeSnapshot $snapshot, array $scopes, string $expectedToken): bool
            {
                return $this->delegate->resetControlPlane($snapshot, $scopes, $expectedToken);
            }

            public function runtimeSnapshot(): ?RuntimeSnapshot
            {
                return $this->delegate->runtimeSnapshot();
            }

            public function putRuntimeSnapshot(RuntimeSnapshot $snapshot): void
            {
                $this->delegate->putRuntimeSnapshot($snapshot);
            }

            public function forgetRuntimeSnapshot(): void
            {
                $this->delegate->forgetRuntimeSnapshot();
            }

            public function incrementMetric(string $metric): void
            {
                $this->delegate->incrementMetric($metric);
            }

            public function probe(): array
            {
                return $this->delegate->probe();
            }

            public function garbageCollect(): array
            {
                return $this->delegate->garbageCollect();
            }

            public function driverName(): string
            {
                return $this->delegate->driverName();
            }
        };

        $failedApplier = new InvalidationApplier($repository, $failedStore);
        $coordinator = new InvalidationCoordinator($repository, $failedStore, $failedApplier);
        $eventId = $coordinator->invalidate(['category:tree'], 'redis-failure-test');

        self::assertNotNull($eventId);
        self::assertSame($eventId, $repository->snapshot()->dirtyEventId);
        self::assertSame(1, $repository->pendingCount());
        self::assertTrue($healthyStore->controlBarrier()?->dirty);
        self::assertSame(['category:tree' => 0], $healthyStore->generations(['category:tree']));

        $healthyApplier = new InvalidationApplier($repository, $healthyStore);
        $reconciler = new OutboxReconciler($repository, $healthyStore, $healthyApplier);
        $result = $reconciler->reconcile(100, 'event:'.$eventId);

        self::assertSame(1, $result['applied']);
        self::assertSame(0, $result['remaining']);
        self::assertSame(['category:tree' => $eventId], $healthyStore->generations(['category:tree']));
        self::assertSame(0, $repository->snapshot()->dirtyEventId);
        self::assertFalse($healthyStore->controlBarrier()?->dirty);

        $secondReplay = $reconciler->reconcile(100);
        self::assertSame(0, $secondReplay['applied']);
        self::assertSame(['category:tree' => $eventId], $healthyStore->generations(['category:tree']));
    }

    public function test_missing_generation_rotates_epoch_before_control_plane_is_rebuilt(): void
    {
        $repository = $this->repository();
        $cache = new PluginCacheDriver('jw-power_cache', 'array');
        $store = new G7PowerCacheStore($cache);
        $initialSnapshot = $repository->snapshot();
        $token = $store->markEmergencyDirty('bootstrap', 'bootstrap');
        self::assertTrue($store->resetControlPlane(
            $initialSnapshot,
            (array) config('jw_power_cache.control_scopes', []),
            $token,
        ));

        $cache->forget('generation:'.hash('sha256', 'site'));
        $settings = new PowerCacheSettings([
            'automatic_recovery' => true,
        ]);
        $applier = new InvalidationApplier($repository, $store);
        $reconciler = new OutboxReconciler($repository, $store, $applier);
        $barrier = new RecoveryBarrier($repository, $store, $reconciler, $settings);

        $result = $barrier->inspect(['site']);

        self::assertTrue($result->ready);
        self::assertNotNull($result->snapshot);
        self::assertNotSame($initialSnapshot->runtimeEpoch, $result->snapshot->runtimeEpoch);
        self::assertSame(['site' => 0], $store->generations(['site']));
        self::assertFalse($store->controlBarrier()?->dirty);
    }

    public function test_dirty_outbox_is_automatically_replayed_before_hits_resume(): void
    {
        $repository = $this->repository();
        $store = $this->arrayStore();
        $eventId = $repository->append(['page:all'], 'process-exit-after-commit');
        $repository->markDirty($eventId);
        $store->markEmergencyDirty('outbox_pending:'.$eventId, 'event:'.$eventId, $eventId);

        $settings = new PowerCacheSettings([
            'automatic_recovery' => true,
            'recovery_batch' => 100,
        ]);
        $applier = new InvalidationApplier($repository, $store);
        $barrier = new RecoveryBarrier(
            $repository,
            $store,
            new OutboxReconciler($repository, $store, $applier),
            $settings,
        );

        $result = $barrier->inspect(['page:all']);

        self::assertTrue($result->ready);
        self::assertSame(0, $repository->pendingCount());
        self::assertSame(0, $repository->snapshot()->dirtyEventId);
        self::assertSame(['page:all' => $eventId], $store->generations(['page:all']));
        self::assertFalse($store->controlBarrier()?->dirty);
    }

    public function test_barrier_write_failure_after_content_commit_keeps_durable_outbox(): void
    {
        $repository = $this->repository();
        $failedStore = $this->createMock(PowerCacheStoreInterface::class);
        $failedStore->method('markEmergencyDirty')->willThrowException(new RuntimeException('redis unavailable'));
        $failedStore->method('advanceGenerations')->willThrowException(new RuntimeException('redis unavailable'));
        $coordinator = new InvalidationCoordinator(
            $repository,
            $failedStore,
            new InvalidationApplier($repository, $failedStore),
        );

        $eventId = $coordinator->invalidate(['site'], 'post-commit-store-failure');

        self::assertNotNull($eventId);
        self::assertSame(1, $repository->pendingCount());
        self::assertSame($eventId, $repository->snapshot()->dirtyEventId);
        self::assertNull($repository->find($eventId)['applied_at']);
    }
}
