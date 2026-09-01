<?php

namespace Plugins\Jw\PowerCache\Contracts;

use Illuminate\Contracts\Cache\Lock;
use Plugins\Jw\PowerCache\Runtime\ControlBarrierState;
use Plugins\Jw\PowerCache\Runtime\RuntimeSnapshot;

interface PowerCacheStoreInterface
{
    /** @return array<string, mixed>|null */
    public function getResponse(string $requestKey): ?array;

    /** @param array<string, mixed> $entry */
    public function putResponse(string $requestKey, array $entry, int $retentionSeconds): void;

    /** @param array<int, string> $scopes @return array<string, int> */
    public function generations(array $scopes): array;

    /** @param array<int, string> $scopes */
    public function advanceGenerations(array $scopes, int $eventId): void;

    public function acquireLock(string $name, int $leaseSeconds): ?Lock;

    public function controlBarrier(): ?ControlBarrierState;

    public function markEmergencyDirty(string $reason, ?string $token = null, int $eventId = 0): string;

    public function clearEmergencyDirty(?string $expectedToken = null): bool;

    /** @param array<int, string> $scopes */
    public function resetControlPlane(RuntimeSnapshot $snapshot, array $scopes, string $expectedToken): bool;

    public function runtimeSnapshot(): ?RuntimeSnapshot;

    public function putRuntimeSnapshot(RuntimeSnapshot $snapshot): void;

    public function forgetRuntimeSnapshot(): void;

    public function incrementMetric(string $metric): void;

    /** @return array<string, mixed> */
    public function probe(): array;

    /** @return array{supported:bool, deleted:int} */
    public function garbageCollect(): array;

    public function driverName(): string;
}
