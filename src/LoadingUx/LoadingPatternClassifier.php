<?php

namespace Plugins\Jw\PowerCache\LoadingUx;

final class LoadingPatternClassifier
{
    public function __construct(private readonly TemplateProfileRegistry $profiles) {}

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, array<string, mixed>>  $ancestors
     * @return array<string, mixed>|null
     */
    public function replacement(
        string $layoutName,
        array $node,
        array $ancestors,
        LoadingUxSettings $settings,
    ): ?array {
        $profile = $this->profiles->match($layoutName, $node, $ancestors);
        if ($profile === null) {
            return null;
        }

        $replacement = [
            'type' => 'composite',
            'name' => 'JWPowerCacheSkeleton',
            'props' => [
                'profile' => $profile['profile'],
                'components' => [],
                'options' => [
                    'animation' => $settings->animation(),
                    'iteration_count' => $settings->iterationCount(),
                    'delay_ms' => $settings->delayMilliseconds(),
                ],
                'className' => 'jwpc-inline-skeleton',
            ],
        ];

        foreach (['id', 'if', 'responsive', '__source'] as $key) {
            if (array_key_exists($key, $node)) {
                $replacement[$key] = $node[$key];
            }
        }

        $replacement['comment'] = 'JW PowerCache Loading UX: '.$profile['id'];

        return $replacement;
    }
}
