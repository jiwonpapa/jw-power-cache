<?php

namespace Plugins\Jw\PowerCache\Operations;

use Plugins\Jw\PowerCache\Contracts\InvalidationRepositoryInterface;
use Plugins\Jw\PowerCache\Contracts\PowerCacheStoreInterface;
use Plugins\Jw\PowerCache\Invalidation\OutboxReconciler;
use Throwable;

final class RestoreFinalizer
{
    public function __construct(
        private readonly InvalidationRepositoryInterface $repository,
        private readonly PowerCacheStoreInterface $store,
        private readonly OutboxReconciler $reconciler,
    ) {}

    /** @return array<string, mixed> */
    public function finalize(): array
    {
        if (! $this->repository->tablesReady()) {
            return $this->failed('JW PowerCache state tables are missing.');
        }

        try {
            $before = $this->repository->snapshot();
            if ($before->siteId === '' || $before->runtimeEpoch === '') {
                return $this->failed('Restored JW PowerCache state is structurally invalid.');
            }

            $control = $this->store->controlBarrier();
            $token = $control?->dirty === true
                ? $control->token
                : 'restore-finalize:'.bin2hex(random_bytes(16));

            if ($control?->dirty !== true) {
                $this->store->markEmergencyDirty(
                    'restore_finalize',
                    $token,
                    $before->dirtyEventId,
                );
            }

            $activeBarrier = $this->store->controlBarrier();
            if ($activeBarrier === null
                || ! $activeBarrier->dirty
                || ! hash_equals($token, $activeBarrier->token)) {
                return $this->failed('Failed to establish the restore safety barrier.');
            }

            $recovery = $this->reconciler->reconcile(1000);
            if ($recovery['error'] !== null || $recovery['remaining'] !== 0) {
                return $this->failed(
                    'Pending invalidation outbox recovery failed; safety barrier remains active.',
                    ['recovery' => $recovery],
                );
            }

            $newEpoch = $this->repository->rotateRuntimeEpoch();
            $snapshot = $this->repository->snapshot();
            $scopes = array_values(array_unique(array_filter(
                (array) config('jw_power_cache.control_scopes', []),
                'is_string',
            )));

            if ($scopes === []
                || ! $this->store->resetControlPlane($snapshot, $scopes, $token)) {
                return $this->failed('Failed to rebuild the cache control plane; safety barrier remains active.');
            }

            return [
                'ok' => true,
                'site_id' => $snapshot->siteId,
                'previous_runtime_epoch' => $before->runtimeEpoch,
                'runtime_epoch' => $newEpoch,
                'pending_outbox' => $this->repository->pendingCount(),
                'control_scopes' => $scopes,
                'recovery' => $recovery,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return $this->failed(
                'Restore finalization failed; safety barrier must be inspected before reopening traffic.',
                ['detail' => $e->getMessage()],
            );
        }
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function failed(string $error, array $context = []): array
    {
        return array_merge([
            'ok' => false,
            'error' => $error,
        ], $context);
    }
}
