<?php

namespace Plugins\Jw\PowerCache\Runtime;

use Plugins\Jw\PowerCache\Contracts\InvalidationRepositoryInterface;
use Plugins\Jw\PowerCache\Contracts\PowerCacheStoreInterface;
use Plugins\Jw\PowerCache\Invalidation\OutboxReconciler;
use Plugins\Jw\PowerCache\Store\ControlPlaneMissingException;
use Throwable;

final class RecoveryBarrier
{
    public function __construct(
        private readonly InvalidationRepositoryInterface $repository,
        private readonly PowerCacheStoreInterface $store,
        private readonly OutboxReconciler $reconciler,
        private readonly PowerCacheSettings $settings,
    ) {}

    /** @param array<int, string> $scopes */
    public function inspect(array $scopes = []): BarrierResult
    {
        try {
            if ($this->store->driverName() === 'file'
                && ! (bool) config('jw_power_cache.file.single_node_ack', false)) {
                return BarrierResult::blocked('file_single_node_unacknowledged');
            }

            $control = $this->store->controlBarrier();
            if ($control === null) {
                return $this->recoverMissingControlPlane('barrier_missing', $scopes);
            }

            if ($control->dirty) {
                return $this->recoverFromDatabase('emergency_dirty', $control, $scopes);
            }

            $snapshot = $this->store->runtimeSnapshot();
            if ($this->readySnapshot($snapshot)) {
                // 무효화 쓰기가 검사 직후 시작된 경우에도 세대 키 조회 전에 장벽을 한 번 더 확인합니다.
                $finalControl = $this->store->controlBarrier();
                if ($finalControl === null) {
                    return $this->recoverMissingControlPlane('barrier_missing', $scopes);
                }
                if ($finalControl->dirty) {
                    return $this->recoverFromDatabase('emergency_dirty', $finalControl, $scopes);
                }

                $this->store->generations($scopes);

                return BarrierResult::ready($snapshot);
            }

            return $this->recoverMissingControlPlane('snapshot_missing', $scopes);
        } catch (ControlPlaneMissingException) {
            try {
                return $this->recoverMissingControlPlane('generation_missing', $scopes);
            } catch (Throwable) {
                return BarrierResult::blocked('barrier_error');
            }
        } catch (Throwable) {
            return BarrierResult::blocked('barrier_error');
        }
    }

    /** @param array<int, string> $scopes */
    private function recoverFromDatabase(
        string $blockedReason,
        ControlBarrierState $control,
        array $scopes,
    ): BarrierResult {
        if (! $this->repository->tablesReady()) {
            return BarrierResult::blocked('tables_missing');
        }

        $snapshot = $this->repository->snapshot();
        if (! $this->structurallyValid($snapshot)) {
            return BarrierResult::blocked('state_invalid');
        }

        if ($snapshot->isDirty() && $this->settings->automaticRecovery()) {
            $this->reconciler->reconcile($this->settings->recoveryBatch(), $control->token);
            $snapshot = $this->repository->snapshot();
        }

        if ($snapshot->isDirty()) {
            return BarrierResult::blocked('outbox_dirty');
        }

        $currentControl = $this->store->controlBarrier();
        if ($currentControl === null) {
            return $this->recoverMissingControlPlane('barrier_missing', $scopes);
        }

        if ($currentControl->dirty) {
            return BarrierResult::blocked($blockedReason);
        }

        $this->store->putRuntimeSnapshot($snapshot);

        try {
            $this->store->generations($scopes);
        } catch (ControlPlaneMissingException) {
            return $this->recoverMissingControlPlane('generation_missing', $scopes);
        }

        return BarrierResult::ready($snapshot);
    }

    /** @param array<int, string> $scopes */
    private function recoverMissingControlPlane(string $reason, array $scopes): BarrierResult
    {
        if (! $this->repository->tablesReady()) {
            return BarrierResult::blocked('tables_missing');
        }

        $token = 'recovery:'.bin2hex(random_bytes(16));
        $this->store->markEmergencyDirty($reason, $token);
        $control = $this->store->controlBarrier();
        if ($control === null) {
            return BarrierResult::blocked('barrier_error');
        }
        if (! hash_equals($token, $control->token)) {
            return $control->dirty
                ? $this->recoverFromDatabase('emergency_dirty', $control, $scopes)
                : BarrierResult::blocked('control_plane_changed');
        }

        $snapshot = $this->repository->snapshot();
        if (! $this->structurallyValid($snapshot)) {
            return BarrierResult::blocked('state_invalid');
        }

        if ($snapshot->isDirty() && $this->settings->automaticRecovery()) {
            $this->reconciler->reconcile($this->settings->recoveryBatch());
            $snapshot = $this->repository->snapshot();
        }
        if ($snapshot->isDirty()) {
            return BarrierResult::blocked('outbox_dirty');
        }

        $this->repository->rotateRuntimeEpoch();
        $snapshot = $this->repository->snapshot();
        $knownScopes = array_values(array_unique(array_merge(
            (array) config('jw_power_cache.control_scopes', []),
            $scopes,
        )));
        if (! $this->store->resetControlPlane($snapshot, $knownScopes, $token)) {
            return BarrierResult::blocked('control_plane_changed');
        }

        return BarrierResult::ready($snapshot);
    }

    private function readySnapshot(?RuntimeSnapshot $snapshot): bool
    {
        return $this->structurallyValid($snapshot) && ! $snapshot->isDirty();
    }

    private function structurallyValid(?RuntimeSnapshot $snapshot): bool
    {
        return $snapshot !== null
            && $snapshot->siteId !== ''
            && $snapshot->runtimeEpoch !== '';
    }
}
