<?php

namespace Plugins\Jw\PowerCache\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Jw\PowerCache\LoadingUx\LoadingUxSettings;

final class LoadingUxSettingsTest extends TestCase
{
    public function test_defaults_are_safe_and_disabled(): void
    {
        $settings = new LoadingUxSettings([]);

        self::assertFalse($settings->enabled());
        self::assertSame('all', $settings->scope());
        self::assertSame('wave', $settings->animation());
        self::assertSame(120, $settings->delayMilliseconds());
        self::assertSame(5, $settings->iterationCount());
    }

    #[DataProvider('boundedValues')]
    public function test_numeric_settings_are_bounded(string $key, int $input, int $expected): void
    {
        $settings = new LoadingUxSettings([$key => $input]);

        $actual = $key === 'loading_ux_delay_ms'
            ? $settings->delayMilliseconds()
            : $settings->iterationCount();

        self::assertSame($expected, $actual);
    }

    /** @return array<string, array{string, int, int}> */
    public static function boundedValues(): array
    {
        return [
            'delay minimum' => ['loading_ux_delay_ms', -1, 0],
            'delay maximum' => ['loading_ux_delay_ms', 1001, 1000],
            'rows minimum' => ['loading_ux_iteration_count', 0, 1],
            'rows maximum' => ['loading_ux_iteration_count', 99, 12],
        ];
    }

    public function test_invalid_enums_fall_back_to_defaults(): void
    {
        $settings = new LoadingUxSettings([
            'loading_ux_scope' => 'private',
            'loading_ux_animation' => 'spin',
        ]);

        self::assertSame('all', $settings->scope());
        self::assertSame('wave', $settings->animation());
    }
}
