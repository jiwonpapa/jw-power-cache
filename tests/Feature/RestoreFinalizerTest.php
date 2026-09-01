<?php

namespace Plugins\Jw\PowerCache\Tests\Feature;

use Plugins\Jw\PowerCache\Invalidation\InvalidationApplier;
use Plugins\Jw\PowerCache\Invalidation\OutboxReconciler;
use Plugins\Jw\PowerCache\Operations\RestoreFinalizer;
use Plugins\Jw\PowerCache\Tests\Support\PowerCacheTestCase;

final class RestoreFinalizerTest extends PowerCacheTestCase
{
    public function test_restore_finalization_replays_outbox_rotates_epoch_and_resets_control_plane(): void
    {
        $repository = $this->repository();
        $store = $this->arrayStore();
        $before = $repository->snapshot();
        $store->putResponse('pre-restore-entry', [
            'runtime_epoch' => $before->runtimeEpoch,
            'body' => 'stale-after-restore',
        ], 300);

        $eventId = $repository->append(['page:all'], 'restored-pending-event');
        $repository->markDirty($eventId);

        $finalizer = new RestoreFinalizer(
            $repository,
            $store,
            new OutboxReconciler(
                $repository,
                $store,
                new InvalidationApplier($repository, $store),
            ),
        );

        $result = $finalizer->finalize();
        $after = $repository->snapshot();

        self::assertTrue($result['ok']);
        self::assertSame($before->siteId, $after->siteId);
        self::assertNotSame($before->runtimeEpoch, $after->runtimeEpoch);
        self::assertSame(0, $after->dirtyEventId);
        self::assertSame(0, $repository->pendingCount());
        self::assertFalse($store->controlBarrier()?->dirty);
        self::assertSame($after->runtimeEpoch, $store->runtimeSnapshot()?->runtimeEpoch);
        self::assertSame([
            'board:all' => 0,
            'category:tree' => 0,
            'page:all' => 0,
            'site' => 0,
        ], $store->generations((array) config('jw_power_cache.control_scopes')));
        self::assertSame(
            $before->runtimeEpoch,
            $store->getResponse('pre-restore-entry')['runtime_epoch'] ?? null,
        );
        self::assertNotSame(
            $after->runtimeEpoch,
            $store->getResponse('pre-restore-entry')['runtime_epoch'] ?? null,
        );
    }
}
