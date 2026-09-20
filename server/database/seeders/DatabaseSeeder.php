<?php

namespace Database\Seeders;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Identity\Models\Account;
use App\Domain\Identity\Models\Marketplace;
use App\Domain\Sync\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 供应商
        $hicustom = Supplier::updateOrCreate(['code' => 'hicustom'], [
            'name' => '指纹科技（HICUSTOM）',
            'base_url' => 'https://www.hicustom.com',
            'status' => 'active',
        ]);

        // 站点（先 UK，其余按需）
        $mps = [
            ['A1F83G8C2ARO7P', 'GB', 'GBP', 'en_GB', 'amazon.co.uk'],
            ['A1PA6795UKMFR9', 'DE', 'EUR', 'de_DE', 'amazon.de'],
            ['APJ6JRA9NG5V4', 'IT', 'EUR', 'it_IT', 'amazon.it'],
            ['A13V1IB3VIYZZH', 'FR', 'EUR', 'fr_FR', 'amazon.fr'],
            ['A1RKKUPIHCS9HS', 'ES', 'EUR', 'es_ES', 'amazon.es'],
            ['ATVPDKIKX0DER', 'US', 'USD', 'en_US', 'amazon.com'],
            ['A1AM78C64UM0Y8', 'MX', 'MXN', 'es_MX', 'amazon.com.mx'],
            ['A2EUQ1WTGCTBG2', 'CA', 'CAD', 'en_CA', 'amazon.ca'],
        ];
        foreach ($mps as $m) {
            Marketplace::updateOrCreate(
                ['platform' => 'amazon', 'code' => $m[0]],
                ['country' => $m[1], 'currency' => $m[2], 'language' => $m[3], 'domain' => $m[4], 'is_active' => true]
            );
        }

        // 亚马逊账号（示例；后续在页面维护）
        Account::updateOrCreate(['name' => 'HHY'], [
            'platform' => 'amazon', 'region' => 'EU', 'status' => 'active', 'notes' => '示例账号（占位，可改）',
        ]);

        // 内部分类树（示例）
        Category::updateOrCreate(['platform' => 'internal', 'code' => '服饰'], ['name_cn' => '服饰', 'is_active' => true]);
        Category::updateOrCreate(['platform' => 'internal', 'code' => '包类'], ['name_cn' => '包类', 'is_active' => true]);

        // 运行时配置（可页面改）
        Setting::updateOrCreate(['group' => 'media', 'key' => 'cdn_domain'], [
            'value_json' => env('OSS_CDN_DOMAIN', ''),
            'description' => '媒体 CDN/自有域名（空=用 OSS 默认域）',
        ]);

        // 管理员
        User::updateOrCreate(['email' => 'admin@weixiubang.club'], [
            'name' => 'Admin',
            'password' => Hash::make(env('ADMIN_INIT_PASSWORD', 'admin123456')),
        ]);

        $this->command->info('Seeded: supplier=hicustom, marketplaces=' . count($mps) . ', account=HHY, admin=admin@weixiubang.club');
    }
}
