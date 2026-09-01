<?php

namespace Plugins\Jw\PowerCache\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Plugins\Jw\PowerCache\LoadingUx\LayoutLoadingFilter;

final class LoadingUxLayoutListener implements HookListenerInterface
{
    public function __construct(private readonly LayoutLoadingFilter $filter) {}

    public static function getSubscribedHooks(): array
    {
        return [
            'core.layout.filter_merged' => [
                'method' => 'filterMergedLayout',
                'priority' => 20,
                'type' => 'filter',
            ],
        ];
    }

    public function handle(...$args): void {}

    /**
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $parentLayout
     * @param  array<string, mixed>  $childLayout
     * @return array<string, mixed>
     */
    public function filterMergedLayout(array $layout, array $parentLayout = [], array $childLayout = []): array
    {
        return $this->filter->filter($layout, $parentLayout, $childLayout);
    }
}
