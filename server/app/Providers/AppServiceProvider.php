<?php

namespace App\Providers;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Iidestiny\Flysystem\Oss\OssAdapter;
use League\Flysystem\Filesystem as Flysystem;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // MySQL 5.7 索引长度限制（utf8mb4 下 varchar(255) 唯一索引会超限）→ 默认字符串长度取 191
        Schema::defaultStringLength(191);

        // 注册阿里云 OSS 文件系统驱动（iidestiny/flysystem-oss 适配器；官方桥接包不支持 Laravel 13）
        Storage::extend('oss', function ($app, $config) {
            $adapter = new OssAdapter(
                $config['access_key_id'] ?? '',
                $config['access_key_secret'] ?? '',
                $config['endpoint'] ?? '',
                $config['bucket'] ?? '',
                (bool) ($config['is_cname'] ?? false),
                (string) ($config['prefix'] ?? ''),
            );

            return new FilesystemAdapter(new Flysystem($adapter), $adapter, $config);
        });
    }
}
