<?php

namespace Plugins\Jw\PowerCache\Runtime;

final readonly class ControlBarrierState
{
    public function __construct(
        public bool $dirty,
        public string $token,
        public int $eventId,
        public string $reason,
        public string $updatedAt,
    ) {}
}
