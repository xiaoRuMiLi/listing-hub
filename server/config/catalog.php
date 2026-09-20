<?php

// 商品域配置：见 DATABASE.md §2
return [
    'default_supplier' => env('CATALOG_DEFAULT_SUPPLIER', 'hicustom'),
    'category_tree_depth' => (int) env('CATALOG_CAT_DEPTH', 6),
    // 贴字/布局模板自动匹配：印刷区宽高比容差
    'auto_match_aspect_tolerance' => (float) env('CATALOG_ASPECT_TOLERANCE', 0.15),
];
