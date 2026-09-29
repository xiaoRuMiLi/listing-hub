<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R11（2026-09-30）：历史数据回填（幂等 · 只补空值，不覆盖已有）。
 *
 * 背景：
 *  - 变体级运费（`product_shipping.product_variant_id` 非空）早期写入时 `product_id` 留空
 *    → 导出/接口里 `product_code` 为空（“无归属”行）。R2 已在读路径兜底 + 写路径回填，
 *    这里把**历史行**也补齐，使各读路径一致。
 *  - `listing_variants.product_code` 早期也可能为空 → 从父体 listing 回填，便于按商品聚合。
 *
 * 幂等：仅 `WHERE ... IS NULL`，可安全重跑。
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            if (Schema::hasTable('product_shipping') && Schema::hasTable('product_variants')
                && Schema::hasColumn('product_shipping', 'product_id')) {
                DB::update(
                    'UPDATE product_shipping ps
                       JOIN product_variants pv ON ps.product_variant_id = pv.id
                        SET ps.product_id = pv.product_id
                      WHERE ps.product_id IS NULL AND ps.product_variant_id IS NOT NULL'
                );
            }
        } catch (\Throwable $e) { /* 单步失败不阻断迁移 */ }

        try {
            if (Schema::hasTable('listing_variants') && Schema::hasTable('listings')
                && Schema::hasColumn('listing_variants', 'product_code')) {
                DB::update(
                    'UPDATE listing_variants lv
                       JOIN listings l ON lv.parent_listing_id = l.id
                        SET lv.product_code = COALESCE(lv.product_code, l.product_code)
                      WHERE lv.product_code IS NULL AND l.product_code IS NOT NULL'
                );
            }
        } catch (\Throwable $e) { /* ignore */ }
    }

    public function down(): void
    {
        // 数据回填不可逆（且只补空值）—— 无需回滚
    }
};
