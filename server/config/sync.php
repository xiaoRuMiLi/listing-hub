<?php

// 同步契约配置：见 API.md §2
return [
    'default_page_size' => (int) env('SYNC_PAGE_SIZE', 200),
    'max_batch' => (int) env('SYNC_MAX_BATCH', 1000),
    'scopes' => ['products', 'variants', 'shipping', 'designs', 'listings', 'assets'],
    'incremental_field' => 'updated_at',
    'conflict_policy' => 'lww',   // 字段级 last-write-wins；带 revision 冲突返回 409
    'csv_bundle' => [
        // 列对齐现有本地文件（拉取即用）
        'products' => 'products.csv',
        'listings' => 'listing_copy.csv',
        'variants' => 'listing_variants.csv',
        'assets' => 'assets.csv',
    ],
];
