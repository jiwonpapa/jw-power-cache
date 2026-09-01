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

        $profileName = (string) $profile['profile'];
        $replacement = $this->div(
            sprintf(
                'jwpc-inline-skeleton jwpc-skeleton jwpc-skeleton--%s jwpc-skeleton--%s',
                $profileName,
                $settings->animation(),
            ),
            $this->content($profileName, $settings->iterationCount()),
        );
        $replacement['props'] += [
            'data-profile' => $profileName,
            'data-jwpc-visible' => 'false',
            'role' => 'status',
            'aria-busy' => 'false',
            'aria-live' => 'polite',
        ];

        foreach (['id', 'if', 'responsive', '__source'] as $key) {
            if (array_key_exists($key, $node)) {
                $replacement[$key] = $node[$key];
            }
        }

        $replacement['comment'] = 'JW PowerCache Loading UX: '.$profile['id'];

        return $replacement;
    }

    /** @return array<int, array<string, mixed>> */
    private function content(string $profile, int $count): array
    {
        $count = max(1, min(12, $count));

        return match ($profile) {
            'board', 'datagrid' => [$this->rows($count, $profile === 'datagrid')],
            'detail' => [$this->div('jwpc-skeleton__detail', [
                $this->line('68', 'lg'),
                $this->line('32', 'sm'),
                $this->span('jwpc-skeleton__hero'),
                $this->line('full'),
                $this->line('92'),
                $this->line('70'),
            ])],
            'product', 'cards' => [$this->grid($count, $profile === 'product')],
            'form', 'settings' => [$this->form($count, $profile === 'settings')],
            default => [$this->grid($count, false)],
        };
    }

    /** @return array<string, mixed> */
    private function rows(int $count, bool $compact): array
    {
        $rows = [];
        for ($index = 0; $index < $count; $index++) {
            $copy = [$this->line($index % 2 ? '72' : '88')];
            if (! $compact) {
                $copy[] = $this->line($index % 3 ? '42' : '56', 'sm');
            }
            $rows[] = $this->div('jwpc-skeleton__row', [
                $this->span('jwpc-skeleton__avatar', ['aria-hidden' => 'true']),
                $this->div('jwpc-skeleton__row-copy', $copy),
                $this->line('10', 'sm'),
            ]);
        }

        return $this->div('jwpc-skeleton__rows', $rows);
    }

    /** @return array<string, mixed> */
    private function grid(int $count, bool $product): array
    {
        $cards = [];
        for ($index = 0; $index < $count; $index++) {
            $cards[] = $this->div('jwpc-skeleton__card', [
                $this->span('jwpc-skeleton__media'.($product ? ' jwpc-skeleton__media--product' : '')),
                $this->line($index % 2 ? '78' : '90'),
                $this->line($product ? '38' : '62', 'sm'),
            ]);
        }

        return $this->div('jwpc-skeleton__grid', $cards);
    }

    /** @return array<string, mixed> */
    private function form(int $count, bool $settings): array
    {
        $fields = [];
        for ($index = 0; $index < min($count, 6); $index++) {
            $fields[] = $this->div('jwpc-skeleton__field', [
                $this->line($index % 2 ? '26' : '18', 'sm'),
                $this->span('jwpc-skeleton__control'),
            ]);
        }

        return $this->div('jwpc-skeleton__form'.($settings ? ' jwpc-skeleton__form--settings' : ''), $fields);
    }

    /** @return array<string, mixed> */
    private function line(string $width, string $size = 'md'): array
    {
        return $this->span("jwpc-skeleton__line jwpc-skeleton__line--{$size} jwpc-skeleton__line--w-{$width}");
    }

    /**
     * @param  array<int, array<string, mixed>>  $children
     * @param  array<string, string>  $props
     * @return array<string, mixed>
     */
    private function div(string $className, array $children = [], array $props = []): array
    {
        return [
            'type' => 'basic',
            'name' => 'Div',
            'props' => ['className' => $className, ...$props],
            'children' => $children,
        ];
    }

    /** @param array<string, string> $props @return array<string, mixed> */
    private function span(string $className, array $props = []): array
    {
        return [
            'type' => 'basic',
            'name' => 'Span',
            'props' => ['className' => $className, ...$props],
        ];
    }
}
