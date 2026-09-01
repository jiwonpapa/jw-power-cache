<?php

namespace Plugins\Jw\PowerCache\LoadingUx;

final class LoadingUxSettings
{
    public const IDENTIFIER = 'jw-power_cache';

    public const DEFAULT_DELAY_MS = 120;

    public const MIN_DELAY_MS = 0;

    public const MAX_DELAY_MS = 1000;

    public const DEFAULT_ITERATION_COUNT = 5;

    public const MIN_ITERATION_COUNT = 1;

    public const MAX_ITERATION_COUNT = 12;

    /** @param array<string, mixed>|null $override */
    public function __construct(private readonly ?array $override = null) {}

    public function enabled(): bool
    {
        return (bool) $this->value('loading_ux_enabled', false);
    }

    public function scope(): string
    {
        $scope = (string) $this->value('loading_ux_scope', 'all');

        return in_array($scope, ['user', 'admin', 'all'], true) ? $scope : 'all';
    }

    public function animation(): string
    {
        $animation = (string) $this->value('loading_ux_animation', 'wave');

        return in_array($animation, ['wave', 'pulse', 'none'], true) ? $animation : 'wave';
    }

    public function delayMilliseconds(): int
    {
        return $this->boundedInt(
            'loading_ux_delay_ms',
            self::DEFAULT_DELAY_MS,
            self::MIN_DELAY_MS,
            self::MAX_DELAY_MS,
        );
    }

    public function iterationCount(): int
    {
        return $this->boundedInt(
            'loading_ux_iteration_count',
            self::DEFAULT_ITERATION_COUNT,
            self::MIN_ITERATION_COUNT,
            self::MAX_ITERATION_COUNT,
        );
    }

    private function boundedInt(string $key, int $default, int $min, int $max): int
    {
        return min($max, max($min, (int) $this->value($key, $default)));
    }

    private function value(string $key, mixed $default): mixed
    {
        if ($this->override !== null) {
            return $this->override[$key] ?? $default;
        }

        return \plugin_setting(self::IDENTIFIER, $key, $default);
    }
}
