<?php

namespace Plugins\Jw\PowerCache\LoadingUx;

use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\Cache\CoreCacheDriver;
use App\Services\ExtensionBundleService;
use App\Services\LayoutService;

class LoadingUxCacheInvalidator
{
    public function __construct(
        private readonly TemplateRepositoryInterface $templates,
        private readonly LayoutRepositoryInterface $layouts,
        private readonly LayoutService $layoutService,
        private readonly ExtensionBundleService $bundles,
        private readonly ?CoreCacheDriver $coreCache = null,
    ) {}

    /** @return array{layouts: int, bundles: int, version: int} */
    public function invalidate(bool $clearPluginBundles = false): array
    {
        $clearedLayouts = 0;

        foreach ($this->templates->getAll() as $template) {
            foreach ($this->layouts->getLayoutNamesByTemplateId((int) $template->id) as $layoutName) {
                $this->layoutService->clearLayoutCache((int) $template->id, (string) $layoutName);
                $clearedLayouts++;
            }
        }

        $clearedBundles = $clearPluginBundles ? $this->bundles->clearBundles('plugin') : 0;
        $cache = $this->coreCache ?? new CoreCacheDriver(config('cache.default', 'file'));
        $currentVersion = (int) $cache->get('ext.cache_version', 0);
        $newVersion = max(time(), $currentVersion + 1);
        $cache->put('ext.cache_version', $newVersion);

        return [
            'layouts' => $clearedLayouts,
            'bundles' => $clearedBundles,
            'version' => $newVersion,
        ];
    }
}
