<?php

// 上架域配置：见 DATABASE.md §8
return [
    'status_enum' => ['draft', 'candidate', 'ready', 'published', 'error', 'archived'],
    'default_platform' => 'amazon',
    'default_currency' => env('LISTING_DEFAULT_CURRENCY', 'GBP'),
    'default_quantity' => (int) env('LISTING_DEFAULT_QUANTITY', 1),
    'require_completeness' => filter_var(env('LISTING_REQUIRE_COMPLETE', true), FILTER_VALIDATE_BOOL),
    'revision_policy' => 'monotonic',   // 每次改动 revision +1，并存 listing_revisions 快照
];
