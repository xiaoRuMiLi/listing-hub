<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R16（2026-09-30）：删除 `product_shipping` 上不合理的唯一索引
 *   `product_shipping_product_country_deleted_unique` = unique(product_id, country, deleted_at)
 *
 * 为什么不合理：
 *   - `product_variant_id` 非空的**变体级**行**也带 `product_id`** →
 *     「商品级 + 变体级」同国两条行一旦被同一次批量软删（同一 `deleted_at`）→
 *     复合唯一键撞车 → `DELETE /products/{code}/shipping?scope=product|all` 报 500。
 *   - 活行的 `deleted_at` 为 NULL（MySQL 复合唯一对 NULL 视为互不相等），
 *     该索引对"活行去重"本就无效。
 *   - 商品级行的身份由 App 层保证（R13：键 `(product_id, product_variant_id=null, country)`）。
 *   变体级唯一索引 `product_shipping_variant_country_deleted_unique` 保留（各变体 id 互异，无冲突）。
 *
 * 幂等：先探测索引存在再 drop；down() 可重建。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_shipping')
            && $this->hasIndex('product_shipping', 'product_shipping_product_country_deleted_unique')) {
            Schema::table('product_shipping', function (Blueprint $t) {
                $t->dropUnique('product_shipping_product_country_deleted_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_shipping')
            && ! $this->hasIndex('product_shipping', 'product_shipping_product_country_deleted_unique')) {
            Schema::table('product_shipping', function (Blueprint $t) {
                $t->unique(['product_id', 'country', 'deleted_at'], 'product_shipping_product_country_deleted_unique');
            });
        }
    }

    /** 探测索引是否存在（不依赖 doctrine/dbal） */
    private function hasIndex(string $table, string $index): bool
    {
        $rows = DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$index]);

        return count($rows) > 0;
    }
};
