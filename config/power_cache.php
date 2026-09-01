<?php

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

];
