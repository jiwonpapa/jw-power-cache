<?php

namespace Plugins\Jw\PowerCache\Store;

use RuntimeException;

final class ControlPlaneMissingException extends RuntimeException
{
    /** @param array<int, string> $scopes */
    public function __construct(public readonly array $scopes)
    {
        parent::__construct('JW PowerCache generation control keys are missing: '.implode(', ', $scopes));
    }
}
