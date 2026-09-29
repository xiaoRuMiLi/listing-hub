<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R1（2026-09-29）：父体 `row_id` 跨端对齐 + 变体跨端键复合索引。
 *
 * 背景（实测）：
 *  - 导出 `listing_copy.csv` 时 `row_id` 用的是**中台自增 id**（`'row_id' => $l->id`），
 *    而子体 `parent_row_id` 是**推送机的本地 row_id** → 拉回本地后「子体找不到父体」。
 *  - 子体 `local_row_id` 也是推送机本地序号 → 与本地库撞号（本机 12669 的 27/28/29 就被撞）。
 *
 * 变更：
 *  1) `listings` 增列 `local_row_id`（= 本地 `listing_copy.row_id`），供导出还原真实 row_id。
 *  2) `listing_variants` 的 `local_row_id` 单列唯一键 → 改为 **(account_id, marketplace, local_row_id) 复合普通索引**。
 *     —— 不能做唯一约束：多机各自从 1 编号，跨机同号是常态；真幂等键仍是业务键 (account_id, marketplace, sku)。
 *
 * 幂等：全部先 hasColumn / try-catch 判断，可安全重跑（MySQL DDL 不可回滚）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('listings') && ! Schema::hasColumn('listings', 'local_row_id')) {
            Schema::table('listings', function (Blueprint $t) {
                $t->unsignedBigInteger('local_row_id')->nullable()->after('product_code');
                $t->index('local_row_id', 'listings_local_row_id_index');
            });
        }

        if (Schema::hasTable('listing_variants')) {
            // 删旧的「单列唯一」（跨机撞号会直接报错的根源）
            try {
                Schema::table('listing_variants', function (Blueprint $t) {
                    $t->dropUnique('listing_variants_local_row_id_unique');
                });
            } catch (\Throwable $e) { /* 不存在则忽略 */ }

            // 加复合普通索引（查询用，不做唯一约束）
            try {
                Schema::table('listing_variants', function (Blueprint $t) {
                    $t->index(['account_id', 'marketplace', 'local_row_id'], 'listing_variants_am_local_row_index');
                });
            } catch (\Throwable $e) { /* 已存在则忽略 */ }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('listing_variants')) {
            try {
                Schema::table('listing_variants', function (Blueprint $t) {
                    $t->dropIndex('listing_variants_am_local_row_index');
                });
            } catch (\Throwable $e) { /* ignore */ }
        }
        if (Schema::hasTable('listings') && Schema::hasColumn('listings', 'local_row_id')) {
            Schema::table('listings', function (Blueprint $t) {
                try { $t->dropIndex('listings_local_row_id_index'); } catch (\Throwable $e) { /* ignore */ }
                $t->dropColumn('local_row_id');
            });
        }
    }
};
