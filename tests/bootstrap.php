<?php

$pluginRoot = dirname(__DIR__);
$candidates = array_filter([
    getenv('G7_ROOT') ?: null,
    dirname($pluginRoot, 2), // plugins/{identifier}
    dirname($pluginRoot, 3), // plugins/_bundled/{identifier}
]);

$g7Root = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate.'/vendor/autoload.php') && is_file($candidate.'/app/Extension/AbstractPlugin.php')) {
        $g7Root = realpath($candidate) ?: $candidate;
        break;
    }
}

if ($g7Root === null) {
    throw new RuntimeException('Gnuboard 7 루트를 찾을 수 없습니다. G7_ROOT 환경변수를 지정하십시오.');
}

define('JW_POWER_CACHE_G7_ROOT', $g7Root);
require_once $g7Root.'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Plugins\\Jw\\PowerCache\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $root = dirname(__DIR__);
    if (str_starts_with($relative, 'Tests\\')) {
        $path = $root.'/tests/'.str_replace('\\', '/', substr($relative, strlen('Tests\\'))).'.php';
        if (is_file($path)) {
            require_once $path;
        }

        return;
    }

    foreach ([$root.'/src/', $root.'/'] as $base) {
        $path = $base.str_replace('\\', '/', $relative).'.php';
        if (is_file($path)) {
            require_once $path;

            return;
        }
    }
});

require_once dirname(__DIR__).'/plugin.php';
