<?php

namespace Plugins\Jw\PowerCache\Invalidation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\Jw\PowerCache\Contracts\InvalidationRepositoryInterface;
use Plugins\Jw\PowerCache\Contracts\PowerCacheStoreInterface;
use Throwable;

final class InvalidationCoordinator
{
    public function __construct(
        private readonly InvalidationRepositoryInterface $repository,
        private readonly PowerCacheStoreInterface $store,
        private readonly InvalidationApplier $applier,
    ) {}

    /**
     * @param  array<int, string>  $scopes
     * @param  array<string, mixed>  $payload
     */
    public function invalidate(array $scopes, string $reason, array $payload = []): ?int
    {
        $scopes = $this->normalizeScopes($scopes);
        if ($scopes === []) {
            return null;
        }

        try {
            if (! $this->repository->tablesReady()) {
                $this->markEmergency('outbox_tables_missing');

                return null;
            }

            if (DB::transactionLevel() > 0) {
                [$eventId, $barrierToken] = $this->appendWithinTransaction($scopes, $reason, $payload, true);
                DB::afterCommit(fn () => $this->applier->apply($eventId, $barrierToken));

                return $eventId;
            }

            [$eventId, $barrierToken] = DB::transaction(
                fn (): array => $this->appendWithinTransaction($scopes, $reason, $payload, false),
            );
            $this->applier->apply($eventId, $barrierToken);

            return $eventId;
        } catch (Throwable $e) {
            Log::critical('JW PowerCache 무효화 아웃박스 기록에 실패했습니다.', [
                'reason' => $reason,
                'scopes' => $scopes,
                'error' => $e->getMessage(),
            ]);
            $this->markEmergency('outbox_write_failed:'.$e->getMessage());

            if (DB::transactionLevel() > 0) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * @param  array<int, string>  $scopes
     * @param  array<string, mixed>  $payload
     * @return array{0:int, 1:string}
     */
    private function appendWithinTransaction(
        array $scopes,
        string $reason,
        array $payload,
        bool $failTransactionWhenBarrierFails,
    ): array {
        $eventId = $this->repository->append($scopes, $reason, $payload);
        $this->repository->markDirty($eventId);
        // DB 커밋 전에 빠른 경로를 닫아, 커밋 직후 프로세스가 종료되어도 이전 응답을 내보내지 않습니다.
        $barrierToken = 'event:'.$eventId;
        try {
            $this->store->markEmergencyDirty(
                'outbox_pending:'.$eventId,
                $barrierToken,
                $eventId,
            );
            DB::afterRollBack(fn () => $this->store->clearEmergencyDirty($barrierToken));
        } catch (Throwable $e) {
            if ($failTransactionWhenBarrierFails) {
                throw $e;
            }

            // 콘텐츠가 이미 커밋된 after 훅에서는 outbox까지 rollback하면 복구 근거를 잃습니다.
            // DB dirty/outbox를 내구성 있게 남기고, 아래 apply/reconcile이 저장소 복구 뒤 재생합니다.
            Log::critical('JW PowerCache 비상 장벽 기록 실패 후 outbox를 dirty 상태로 보존합니다.', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
        }

        return [$eventId, $barrierToken];
    }

    private function markEmergency(string $reason): void
    {
        try {
            $this->store->markEmergencyDirty($reason);
        } catch (Throwable $e) {
            Log::critical('JW PowerCache 저장소 비상 장벽 기록에도 실패했습니다.', [
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @param array<int, string> $scopes @return array<int, string> */
    private function normalizeScopes(array $scopes): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn (mixed $scope): string => trim((string) $scope),
            $scopes,
        ))));
        sort($normalized, SORT_STRING);

        return $normalized;
    }
}
