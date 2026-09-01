<?php

namespace Plugins\Jw\PowerCache\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Jw\PowerCache\LoadingUx\LoadingUxCacheInvalidator;
use Plugins\Jw\PowerCache\LoadingUx\LoadingUxSettings;

final class LoadingUxCacheListener implements HookListenerInterface
{
    public function __construct(private readonly LoadingUxCacheInvalidator $invalidator) {}

    public static function getSubscribedHooks(): array
    {
        return [
            'core.plugin_settings.after_save' => [
                'method' => 'afterSettingsSave',
                'priority' => 20,
                'type' => 'action',
                'sync' => true,
            ],
            'core.plugin_settings.after_reset' => [
                'method' => 'afterSettingsReset',
                'priority' => 20,
                'type' => 'action',
                'sync' => true,
            ],
            'core.plugins.updated' => [
                'method' => 'afterPluginUpdate',
                'priority' => 20,
                'type' => 'action',
                'sync' => true,
            ],
        ];
    }

    public function handle(...$args): void {}

    /** @param array<string, mixed> $settings */
    public function afterSettingsSave(string $identifier, array $settings, bool $result): void
    {
        if ($identifier === LoadingUxSettings::IDENTIFIER && $result) {
            $this->invalidator->invalidate(true);
        }
    }

    public function afterSettingsReset(string $identifier): void
    {
        if ($identifier === LoadingUxSettings::IDENTIFIER) {
            $this->invalidator->invalidate(true);
        }
    }

    public function afterPluginUpdate(string $identifier): void
    {
        if ($identifier === LoadingUxSettings::IDENTIFIER) {
            $this->invalidator->invalidate(true);
        }
    }
}
