<?php

namespace Plugins\Jw\PowerCache\Runtime;

final readonly class RuntimeSnapshot
{
    public function __construct(
        public string $siteId,
        public string $runtimeEpoch,
        public int $dirtyEventId,
    ) {}

    public function isDirty(): bool
    {
        return $this->dirtyEventId > 0;
    }
}
