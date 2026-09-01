<?php

use Illuminate\Support\Str;

return [
    'format_version' => 2,
    'policy_version' => 'guest-api-v2',
    'outbox_retention_days' => (int) env('JW_POWER_CACHE_OUTBOX_RETENTION_DAYS', 7),
    'control_scopes' => [
        'site',
        'page:all',
        'category:tree',
        'board:all',
    ],

    'stores' => [
        'file' => 'jw_power_cache_file',
        'redis' => 'jw_power_cache_redis',
        'array' => 'jw_power_cache_array',
    ],

    'file' => [
        'path' => env('JW_POWER_CACHE_FILE_PATH', storage_path('app/jw-power-cache/cache')),
        'gc_safe_root' => env('JW_POWER_CACHE_FILE_GC_SAFE_ROOT', storage_path('app/jw-power-cache')),
        'single_node_ack' => (bool) env('JW_POWER_CACHE_FILE_SINGLE_NODE', false),
    ],

    'redis' => [
        'connection' => 'jw_power_cache',
        'url' => env('JW_POWER_CACHE_REDIS_URL'),
        'host' => env('JW_POWER_CACHE_REDIS_HOST', env('REDIS_HOST', '127.0.0.1')),
        'username' => env('JW_POWER_CACHE_REDIS_USERNAME', env('REDIS_USERNAME')),
        'password' => env('JW_POWER_CACHE_REDIS_PASSWORD', env('REDIS_PASSWORD')),
        'port' => env('JW_POWER_CACHE_REDIS_PORT', env('REDIS_PORT', 6379)),
        'database' => env('JW_POWER_CACHE_REDIS_DB', 7),
        'prefix' => env('JW_POWER_CACHE_REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'gnuboard7')).':jwpc:'),
    ],
];
