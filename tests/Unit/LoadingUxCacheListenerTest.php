<?php

namespace Plugins\Jw\PowerCache\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\Jw\PowerCache\Listeners\LoadingUxCacheListener;
use Plugins\Jw\PowerCache\LoadingUx\LoadingUxCacheInvalidator;

final class LoadingUxCacheListenerTest extends TestCase
{
    public function test_settings_save_and_reset_clear_layout_and_plugin_bundle_caches(): void
    {
        $invalidator = $this->createMock(LoadingUxCacheInvalidator::class);
        $invalidator->expects(self::exactly(2))->method('invalidate')->with(true);
        $listener = new LoadingUxCacheListener($invalidator);

        $listener->afterSettingsSave('jw-power_cache', ['loading_ux_enabled' => true], true);
        $listener->afterSettingsReset('jw-power_cache');
    }

    public function test_unrelated_or_failed_saves_do_not_clear_caches(): void
    {
        $invalidator = $this->createMock(LoadingUxCacheInvalidator::class);
        $invalidator->expects(self::never())->method('invalidate');
        $listener = new LoadingUxCacheListener($invalidator);

        $listener->afterSettingsSave('other-plugin', [], true);
        $listener->afterSettingsSave('jw-power_cache', [], false);
        $listener->afterSettingsReset('other-plugin');
        $listener->afterPluginUpdate('other-plugin');
    }

    public function test_plugin_update_clears_the_plugin_bundle_cache(): void
    {
        $invalidator = $this->createMock(LoadingUxCacheInvalidator::class);
        $invalidator->expects(self::once())->method('invalidate')->with(true);

        (new LoadingUxCacheListener($invalidator))->afterPluginUpdate('jw-power_cache');
    }
}
