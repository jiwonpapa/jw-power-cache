<?php

namespace Plugins\Jw\PowerCache\Runtime;

use App\Extension\HookManager;

final class CoreCompatibility
{
    public const REQUIRED_TRANSACTIONAL_ACTIONS_VERSION = 1;

    public function __construct(private readonly ?bool $override = null) {}

    public function supportsTransactionalActions(): bool
    {
        if ($this->override !== null) {
            return $this->override;
        }

        $constant = HookManager::class.'::TRANSACTIONAL_ACTIONS_VERSION';

        return defined($constant)
            && (int) constant($constant) >= self::REQUIRED_TRANSACTIONAL_ACTIONS_VERSION;
    }
}
