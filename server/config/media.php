<?php

// 媒体/图床配置（OSS）：见 CONFIG.md
return [
    'disk' => env('MEDIA_DISK', 'oss'),
    'path_prefix' => env('OSS_PATH_PREFIX', 'blobs'),
    'public_base_url' => env('MEDIA_PUBLIC_BASE_URL', ''),
    // ★ 图片 URL 归一的"自有域名白名单"（幂等：只改写非白名单 host）
    'allowed_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('MEDIA_ALLOWED_HOSTS', ''))))),
    'presign_ttl' => (int) env('MEDIA_PRESIGN_TTL', 900),
    'verify_enabled' => filter_var(env('MEDIA_VERIFY_ENABLED', true), FILTER_VALIDATE_BOOL),
    'video_max_mb' => (int) env('MEDIA_VIDEO_MAX_MB', 500),
    'phash_enabled' => filter_var(env('MEDIA_PHASH_ENABLED', false), FILTER_VALIDATE_BOOL),
];
