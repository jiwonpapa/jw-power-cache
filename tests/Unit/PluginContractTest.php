<?php

namespace Plugins\Jw\PowerCache\Tests\Unit;

use App\Rules\ValidLayoutStructure;
use App\Rules\WhitelistedEndpoint;
use PHPUnit\Framework\TestCase;
use Plugins\Jw\PowerCache\Listeners\ContentInvalidationListener;
use Plugins\Jw\PowerCache\Listeners\CoreInvalidationListener;
use Plugins\Jw\PowerCache\Plugin;

final class PluginContractTest extends TestCase
{
    public function test_manifest_middleware_and_settings_contracts(): void
    {
        $plugin = new Plugin;
        $manifest = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/plugin.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        // AbstractPlugin::getIdentifier()는 설치 디렉터리명에서 추론한다.
        // 독립 저장소 루트의 표시용 폴더명과 G7 설치 식별자를 분리해 검증한다.
        self::assertSame('jw-power_cache', $manifest['identifier']);
        self::assertSame('0.3.0-alpha.3', $plugin->getVersion());
        self::assertSame('observe', $plugin->getConfigValues()['mode']);
        self::assertSame('file', $plugin->getConfigValues()['store_driver']);

        $middleware = $plugin->getMiddleware();
        self::assertCount(1, $middleware);
        self::assertSame(['api'], $middleware[0]['groups']);
        self::assertSame('after_core', $middleware[0]['timing']);
        self::assertCount(4, $middleware[0]['targets']);
        self::assertContains('api.modules.sirsoft-board.boards.posts.index', $middleware[0]['targets']);
    }

    public function test_every_invalidation_hook_is_forced_synchronous(): void
    {
        foreach ([ContentInvalidationListener::class, CoreInvalidationListener::class] as $listener) {
            foreach ($listener::getSubscribedHooks() as $hook => $config) {
                self::assertTrue($config['sync'] ?? false, "{$hook} must be synchronous");
                self::assertSame('action', $config['type'] ?? null);
            }
        }
    }

    public function test_recovery_and_gc_schedules_are_registered(): void
    {
        $schedules = (new Plugin)->getSchedules();

        self::assertContains([
            'command' => 'power-cache:reconcile --limit=100',
            'schedule' => 'everyMinute',
            'description' => 'JW PowerCache 미적용 무효화 아웃박스 복구',
            'enabled_config' => null,
        ], $schedules);
        self::assertContains([
            'command' => 'power-cache:gc',
            'schedule' => 'daily',
            'description' => 'JW PowerCache 적용 완료 아웃박스 이력 정리',
            'enabled_config' => null,
        ], $schedules);
    }

    public function test_board_list_mutations_and_guest_presentation_changes_are_covered(): void
    {
        $contentHooks = ContentInvalidationListener::getSubscribedHooks();
        foreach ([
            'sirsoft-board.board.after_update',
            'sirsoft-board.post.after_create',
            'sirsoft-board.post.after_update',
            'sirsoft-board.post.after_delete',
            'sirsoft-board.comment.after_create',
            'sirsoft-board.attachment.after_upload',
            'sirsoft-board.permissions.after_update',
        ] as $hook) {
            self::assertSame('handleBoardMutation', $contentHooks[$hook]['method'] ?? null, $hook);
        }

        $coreHooks = CoreInvalidationListener::getSubscribedHooks();
        foreach ([
            'core.role.after_sync_permissions',
            'core.user.after_update',
            'core.attachment.after_upload',
        ] as $hook) {
            self::assertSame('handleBoardPresentationMutation', $coreHooks[$hook]['method'] ?? null, $hook);
        }
    }

    public function test_operational_settings_are_not_exposed_to_public_frontend_config(): void
    {
        $settings = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/config/settings/defaults.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($settings['frontend_schema'] as $name => $schema) {
            self::assertFalse($schema['expose'] ?? true, "{$name} must stay admin-only");
        }
    }

    public function test_settings_defaults_schema_and_admin_form_stay_in_sync(): void
    {
        $plugin = new Plugin;
        $settings = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/config/settings/defaults.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $layout = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/resources/layouts/admin/plugin_settings.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $expected = array_keys($plugin->getSettingsSchema());
        $defaults = array_keys($settings['defaults']);
        $frontend = array_keys($settings['frontend_schema']);
        $configValues = array_keys($plugin->getConfigValues());
        $layoutSchema = array_keys($layout['schema'] ?? []);
        $adminFields = [];
        $this->collectNamedInputs($layout, $adminFields);

        sort($expected);
        sort($defaults);
        sort($frontend);
        sort($configValues);
        sort($layoutSchema);
        sort($adminFields);

        self::assertSame($expected, $defaults, 'defaults.json defaults mismatch');
        self::assertSame($expected, $frontend, 'frontend_schema mismatch');
        self::assertSame($expected, $configValues, 'Plugin config values mismatch');
        self::assertSame($expected, $layoutSchema, 'Admin layout schema mismatch');
        self::assertSame($expected, array_values(array_unique($adminFields)), 'Admin form fields mismatch');
    }

    public function test_admin_settings_layout_passes_core_structure_and_endpoint_rules(): void
    {
        $layout = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/resources/layouts/admin/plugin_settings.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $failures = [];
        (new ValidLayoutStructure)->validate(
            'layout',
            $layout,
            static function (string $message) use (&$failures): void {
                $failures[] = $message;
            },
        );

        foreach ($layout['data_sources'] ?? [] as $source) {
            (new WhitelistedEndpoint)->validate(
                'endpoint',
                $source['endpoint'] ?? '',
                static function (string $message) use (&$failures): void {
                    $failures[] = $message;
                },
            );
        }

        self::assertSame([], $failures);
    }

    /** @param array<int, string> $names */
    private function collectNamedInputs(mixed $node, array &$names): void
    {
        if (! is_array($node)) {
            return;
        }

        if (in_array($node['name'] ?? null, ['Input', 'Select'], true)
            && is_string($node['props']['name'] ?? null)) {
            $names[] = $node['props']['name'];
        }

        foreach ($node as $child) {
            $this->collectNamedInputs($child, $names);
        }
    }
}
