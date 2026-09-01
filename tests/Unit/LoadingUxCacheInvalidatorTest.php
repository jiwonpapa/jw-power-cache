<?php

namespace Plugins\Jw\PowerCache\Tests\Unit;

use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Models\Template;
use App\Services\ExtensionBundleService;
use App\Services\LayoutService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Plugins\Jw\PowerCache\LoadingUx\LoadingUxCacheInvalidator;

final class LoadingUxCacheInvalidatorTest extends TestCase
{
    public function test_all_merged_layouts_and_only_plugin_bundles_are_invalidated(): void
    {
        $first = new Template;
        $first->id = 11;
        $second = new Template;
        $second->id = 22;

        $templates = $this->createMock(TemplateRepositoryInterface::class);
        $templates->method('getAll')->willReturn(new EloquentCollection([$first, $second]));
        $layouts = $this->createMock(LayoutRepositoryInterface::class);
        $layouts->method('getLayoutNamesByTemplateId')->willReturnMap([
            [11, new Collection(['_user_base', 'board/index'])],
            [22, new Collection(['_admin_base'])],
        ]);
        $layoutService = $this->createMock(LayoutService::class);
        $cleared = [];
        $layoutService->expects(self::exactly(3))->method('clearLayoutCache')->willReturnCallback(
            static function (int $templateId, string $layoutName) use (&$cleared): void {
                $cleared[] = [$templateId, $layoutName];
            },
        );
        $bundles = $this->createMock(ExtensionBundleService::class);
        $bundles->expects(self::once())->method('clearBundles')->with('plugin')->willReturn(2);
        $cache = $this->createMock(CoreCacheDriver::class);
        $cache->expects(self::once())->method('get')->with('ext.cache_version', 0)->willReturn(500);
        $cache->expects(self::once())->method('put')->with(
            'ext.cache_version',
            self::callback(static fn (int $version): bool => $version > 500),
        );

        $result = (new LoadingUxCacheInvalidator($templates, $layouts, $layoutService, $bundles, $cache))->invalidate(true);

        self::assertSame(3, $result['layouts']);
        self::assertSame(2, $result['bundles']);
        self::assertGreaterThan(500, $result['version']);
        self::assertSame([
            [11, '_user_base'],
            [11, 'board/index'],
            [22, '_admin_base'],
        ], $cleared);
    }
}
