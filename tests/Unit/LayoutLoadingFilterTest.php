<?php

namespace Plugins\Jw\PowerCache\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Jw\PowerCache\LoadingUx\LayoutLoadingFilter;
use Plugins\Jw\PowerCache\LoadingUx\LoadingPatternClassifier;
use Plugins\Jw\PowerCache\LoadingUx\LoadingUxSettings;
use Plugins\Jw\PowerCache\LoadingUx\TemplateProfileRegistry;

final class LayoutLoadingFilterTest extends TestCase
{
    public function test_disabled_feature_returns_the_input_unchanged(): void
    {
        $layout = $this->spinnerLayout('board/index');

        self::assertSame($layout, $this->filter(['loading_ux_enabled' => false])->filter($layout));
    }

    public function test_overlay_is_purely_transformed_and_preserves_control_fields(): void
    {
        $layout = $this->spinnerLayout('board/index');
        $original = $layout;

        $result = $this->filter([
            'loading_ux_enabled' => true,
            'loading_ux_animation' => 'pulse',
            'loading_ux_delay_ms' => 180,
            'loading_ux_iteration_count' => 7,
        ])->filter($layout, ['layout_name' => '_user_base'], $layout);

        self::assertSame($original, $layout, 'The input array must not be mutated.');
        self::assertSame('skeleton', $result['transition_overlay']['style']);
        self::assertSame('main_content_area', $result['transition_overlay']['target']);
        self::assertSame('app_content', $result['transition_overlay']['fallback_target']);
        self::assertSame(['posts'], $result['transition_overlay']['wait_for']);
        self::assertTrue($result['transition_overlay']['enabled']);
        self::assertArrayNotHasKey('spinner', $result['transition_overlay']);
        self::assertSame([
            'component' => 'JWPowerCacheSkeleton',
            'animation' => 'pulse',
            'iteration_count' => 7,
            'delay_ms' => 180,
        ], $result['transition_overlay']['skeleton']);
    }

    #[DataProvider('scopeCases')]
    public function test_scope_is_enforced(string $scope, string $baseName, bool $transformed): void
    {
        $layout = $this->spinnerLayout($baseName === '_admin_base' ? 'admin_plugins' : 'board/index');
        $result = $this->filter([
            'loading_ux_enabled' => true,
            'loading_ux_scope' => $scope,
        ])->filter($layout, ['layout_name' => $baseName], $layout);

        self::assertSame($transformed ? 'skeleton' : 'spinner', $result['transition_overlay']['style']);
    }

    /** @return array<string, array{string, string, bool}> */
    public static function scopeCases(): array
    {
        return [
            'user applies to user' => ['user', '_user_base', true],
            'user excludes admin' => ['user', '_admin_base', false],
            'admin applies to admin' => ['admin', '_admin_base', true],
            'admin excludes user' => ['admin', '_user_base', false],
            'all applies to user' => ['all', '_user_base', true],
            'all applies to admin' => ['all', '_admin_base', true],
        ];
    }

    #[DataProvider('cacheModes')]
    public function test_loading_ux_is_independent_from_cache_mode(string $mode): void
    {
        $layout = $this->spinnerLayout('board/index');
        $result = $this->filter([
            'mode' => $mode,
            'loading_ux_enabled' => true,
        ])->filter($layout, ['layout_name' => '_user_base'], $layout);

        self::assertSame('skeleton', $result['transition_overlay']['style']);
    }

    /** @return array<string, array{string}> */
    public static function cacheModes(): array
    {
        return ['observe' => ['observe'], 'active' => ['active'], 'bypass' => ['bypass']];
    }

    #[DataProvider('officialProfiles')]
    public function test_only_profiled_official_large_spinners_are_replaced(
        string $layoutName,
        string $nodeName,
        array $nodeProps,
        array $parentClasses,
        string $condition,
        string $expectedProfile,
    ): void {
        $layout = [
            'layout_name' => $layoutName,
            'components' => [[
                'name' => 'Div',
                'if' => $condition,
                'children' => [[
                    'name' => 'Div',
                    'props' => ['className' => implode(' ', $parentClasses)],
                    'children' => [[
                        'id' => 'official-spinner',
                        'type' => 'basic',
                        'name' => $nodeName,
                        'props' => $nodeProps,
                    ]],
                ]],
            ]],
        ];

        $result = $this->filter(['loading_ux_enabled' => true])->filter($layout);
        $replacement = $result['components'][0]['children'][0]['children'][0];

        self::assertSame('JWPowerCacheSkeleton', $replacement['name']);
        self::assertSame('official-spinner', $replacement['id']);
        self::assertSame($expectedProfile, $replacement['props']['profile']);
    }

    /** @return array<string, array{string, string, array<string, string>, array<int, string>, string, string}> */
    public static function officialProfiles(): array
    {
        return [
            'board list' => ['board/index', 'Div', ['className' => 'animate-spin h-12 w-12 border-4'], ['flex-col', 'items-center', 'py-16'], '{{!posts?.data?.board && !_global.hasError}}', 'board'],
            'profile detail' => ['users/show', 'Div', ['className' => 'animate-spin h-8 w-8 border-b-2'], ['justify-center', 'items-center', 'py-20'], '{{profile.loading}}', 'detail'],
            'user posts' => ['users/posts', 'Div', ['className' => 'animate-spin h-8 w-8 border-b-2'], ['justify-center', 'items-center', 'py-20'], '{{userPosts.loading && !userPosts.data}}', 'board'],
            'shop reorder' => ['shop/reorder', 'Icon', ['name' => 'loader-2', 'className' => 'animate-spin text-4xl'], ['flex-col', 'justify-center', 'py-16'], "{{_local.status === 'pending'}}", 'product'],
        ];
    }

    public function test_button_modal_and_unknown_third_party_spinners_remain_unchanged(): void
    {
        $actionSpinner = [
            'type' => 'basic',
            'name' => 'Icon',
            'if' => '{{_local.isSaving}}',
            'props' => ['name' => 'loader-2', 'className' => 'animate-spin h-4 w-4'],
        ];
        $layout = [
            'layout_name' => 'vendor/custom',
            'transition_overlay' => ['enabled' => true, 'style' => 'spinner', 'target' => 'content'],
            'components' => [[
                'type' => 'basic',
                'name' => 'Button',
                'children' => [$actionSpinner],
            ], [
                'type' => 'composite',
                'name' => 'Modal',
                'children' => [$actionSpinner],
            ], $actionSpinner],
        ];

        $result = $this->filter(['loading_ux_enabled' => true])->filter($layout);

        self::assertSame('skeleton', $result['transition_overlay']['style']);
        self::assertSame($layout['components'], $result['components']);
    }

    /** @param array<string, mixed> $settings */
    private function filter(array $settings): LayoutLoadingFilter
    {
        return new LayoutLoadingFilter(
            new LoadingUxSettings($settings),
            new LoadingPatternClassifier(new TemplateProfileRegistry),
        );
    }

    /** @return array<string, mixed> */
    private function spinnerLayout(string $layoutName): array
    {
        return [
            'layout_name' => $layoutName,
            'transition_overlay' => [
                'enabled' => true,
                'style' => 'spinner',
                'target' => 'main_content_area',
                'fallback_target' => 'app_content',
                'wait_for' => ['posts'],
                'spinner' => ['component' => 'PageLoading', 'text' => 'Loading'],
            ],
            'components' => [],
        ];
    }
}
