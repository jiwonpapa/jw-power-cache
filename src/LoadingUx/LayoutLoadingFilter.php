<?php

namespace Plugins\Jw\PowerCache\LoadingUx;

final class LayoutLoadingFilter
{
    public function __construct(
        private readonly LoadingUxSettings $settings,
        private readonly LoadingPatternClassifier $classifier,
    ) {}

    /**
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $parentLayout
     * @param  array<string, mixed>  $childLayout
     * @return array<string, mixed>
     */
    public function filter(array $layout, array $parentLayout = [], array $childLayout = []): array
    {
        if (! $this->settings->enabled() || ! $this->scopeMatches($layout, $parentLayout, $childLayout)) {
            return $layout;
        }

        $result = $layout;
        $overlay = $result['transition_overlay'] ?? null;

        if (is_array($overlay) && ($overlay['style'] ?? null) === 'spinner') {
            $overlay['style'] = 'skeleton';
            unset($overlay['spinner']);
            $overlay['skeleton'] = [
                'component' => 'JWPowerCacheSkeleton',
                'animation' => $this->settings->animation(),
                'iteration_count' => $this->settings->iterationCount(),
                'delay_ms' => $this->settings->delayMilliseconds(),
            ];
            $result['transition_overlay'] = $overlay;
        }

        $layoutName = (string) ($result['layout_name'] ?? $childLayout['layout_name'] ?? '');
        if (isset($result['components']) && is_array($result['components'])) {
            $result['components'] = $this->transformNodes($result['components'], $layoutName);
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @param  array<int, array<string, mixed>>  $ancestors
     * @return array<int, mixed>
     */
    private function transformNodes(array $nodes, string $layoutName, array $ancestors = []): array
    {
        $transformed = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                $transformed[] = $node;

                continue;
            }

            $replacement = $this->classifier->replacement($layoutName, $node, $ancestors, $this->settings);
            if ($replacement !== null) {
                $transformed[] = $replacement;

                continue;
            }

            $copy = $node;
            if (isset($copy['children']) && is_array($copy['children'])) {
                $copy['children'] = $this->transformNodes(
                    $copy['children'],
                    $layoutName,
                    [...$ancestors, $node],
                );
            }

            $transformed[] = $copy;
        }

        return $transformed;
    }

    /**
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>  $parentLayout
     * @param  array<string, mixed>  $childLayout
     */
    private function scopeMatches(array $layout, array $parentLayout, array $childLayout): bool
    {
        $scope = $this->settings->scope();
        if ($scope === 'all') {
            return true;
        }

        $names = array_filter([
            $layout['layout_name'] ?? null,
            $parentLayout['layout_name'] ?? null,
            $childLayout['layout_name'] ?? null,
        ], 'is_string');

        $isAdmin = false;
        $isUser = false;
        foreach ($names as $name) {
            $isAdmin = $isAdmin || $name === '_admin_base' || str_starts_with($name, 'admin_');
            $isUser = $isUser || $name === '_user_base' || str_contains($name, '/');
        }

        return $scope === 'admin' ? $isAdmin : ($isUser && ! $isAdmin);
    }
}
