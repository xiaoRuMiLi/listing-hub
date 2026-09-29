<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 删除 API 支撑（2026-09-29）
 * ① designs / product_variants / product_shipping 补 deleted_at（软删）
 * ② unique 改复合（含 deleted_at）—— 让「软删后可同名重建」
 * ③ users 加 is_admin（删除权限：非管理员只删自己推的）
 *
 * 线上索引实测（2026-09-29）：
 *   product_variants  → 无 unique（只有 PRIMARY + product_id / (product_id,color) / (product_id,size) index）
 *   product_shipping  → unique(product_variant_id, country) + unique(product_id, country)
 *                       ⚠️ product_variant_id 是外键 → 其 unique 索引被 FK 依赖，**不能直接 drop**
 *   designs           → unique(design_code)
 *
 * ⚠️ 关键修复（2026-09-29 部署报错 1553）：
 *   MySQL 不允许 drop 被外键依赖的索引。必须先 drop 外键 → drop 旧 unique → 建新复合 unique → 重建外键。
 *
 * 幂等：全部先 hasColumn / hasIndex / 外键存在性探测再改，可安全重跑。
 * 首个管理员：admin@weixiubang.club（可用 env HUB_ADMIN_EMAIL 覆盖）
 */
return new class extends Migration
{
    public function up(): void
    {
        // ① 补 deleted_at（三张从属表）
        foreach (['designs', 'product_variants', 'product_shipping'] as $tbl) {
            if (Schema::hasTable($tbl) && ! Schema::hasColumn($tbl, 'deleted_at')) {
                Schema::table($tbl, fn (Blueprint $t) => $t->softDeletes());
            }
        }

        // ③ users.is_admin
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', fn (Blueprint $t) => $t->boolean('is_admin')->default(false)->after('email'));
        }
        // 设管理员（独立于加列；幂等；已存在也能补设）
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'is_admin')) {
            $admin = env('HUB_ADMIN_EMAIL', 'admin@weixiubang.club');
            DB::table('users')->where('email', $admin)->update(['is_admin' => true]);
        }

        // ② designs: unique(design_code) → unique(design_code, deleted_at)（无外键依赖，直接改）
        if (Schema::hasTable('designs') && $this->hasIndex('designs', 'designs_design_code_unique')) {
            Schema::table('designs', fn (Blueprint $t) => $t->dropUnique('designs_design_code_unique'));
        }
        if (Schema::hasTable('designs') && ! $this->hasIndex('designs', 'designs_design_code_deleted_at_unique')) {
            Schema::table('designs', fn (Blueprint $t) => $t->unique(['design_code', 'deleted_at'], 'designs_design_code_deleted_at_unique'));
        }

        // product_shipping: 2 个 unique → 改复合
        //   ⚠️ product_variant_id 外键依赖其 unique 索引 → 必须先落外键
        if (Schema::hasTable('product_shipping')) {
            $fk = $this->foreignKeyOn('product_shipping', 'product_variant_id');

            // 先删外键（若存在）
            if ($fk) {
                Schema::table('product_shipping', fn (Blueprint $t) => $t->dropForeign($fk));
            }

            if ($this->hasIndex('product_shipping', 'product_shipping_product_variant_id_country_unique')) {
                Schema::table('product_shipping', fn (Blueprint $t) => $t->dropUnique('product_shipping_product_variant_id_country_unique'));
            }
            if ($this->hasIndex('product_shipping', 'product_shipping_product_id_country_unique')) {
                Schema::table('product_shipping', fn (Blueprint $t) => $t->dropUnique('product_shipping_product_id_country_unique'));
            }

            if (! $this->hasIndex('product_shipping', 'product_shipping_variant_country_deleted_unique')) {
                Schema::table('product_shipping', fn (Blueprint $t) => $t->unique(['product_variant_id', 'country', 'deleted_at'], 'product_shipping_variant_country_deleted_unique'));
            }
            if (! $this->hasIndex('product_shipping', 'product_shipping_product_country_deleted_unique')) {
                Schema::table('product_shipping', fn (Blueprint $t) => $t->unique(['product_id', 'country', 'deleted_at'], 'product_shipping_product_country_deleted_unique'));
            }

            // 重建外键（cascadeOnDelete，与 000001 一致）
            if ($fk) {
                Schema::table('product_shipping', function (Blueprint $t) {
                    $t->foreign('product_variant_id')
                        ->references('id')->on('product_variants')
                        ->cascadeOnDelete();
                });
            }
        }

        // product_variants: 新建 unique(product_id, external_variant_id, deleted_at)（线上原无）
        if (Schema::hasTable('product_variants') && ! $this->hasIndex('product_variants', 'product_variants_product_ext_deleted_unique')) {
            Schema::table('product_variants', fn (Blueprint $t) => $t->unique(['product_id', 'external_variant_id', 'deleted_at'], 'product_variants_product_ext_deleted_unique'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('designs')) {
            if ($this->hasIndex('designs', 'designs_design_code_deleted_at_unique')) {
                Schema::table('designs', fn (Blueprint $t) => $t->dropUnique('designs_design_code_deleted_at_unique'));
            }
            if (! $this->hasIndex('designs', 'designs_design_code_unique')) {
                Schema::table('designs', fn (Blueprint $t) => $t->unique('design_code', 'designs_design_code_unique'));
            }
        }
        if (Schema::hasTable('product_shipping')) {
            $fk = $this->foreignKeyOn('product_shipping', 'product_variant_id');
            if ($fk) {
                Schema::table('product_shipping', fn (Blueprint $t) => $t->dropForeign($fk));
            }
            if ($this->hasIndex('product_shipping', 'product_shipping_variant_country_deleted_unique')) {
                Schema::table('product_shipping', fn (Blueprint $t) => $t->dropUnique('product_shipping_variant_country_deleted_unique'));
            }
            if ($this->hasIndex('product_shipping', 'product_shipping_product_country_deleted_unique')) {
                Schema::table('product_shipping', fn (Blueprint $t) => $t->dropUnique('product_shipping_product_country_deleted_unique'));
            }
            if (! $this->hasIndex('product_shipping', 'product_shipping_product_variant_id_country_unique')) {
                Schema::table('product_shipping', fn (Blueprint $t) => $t->unique(['product_variant_id', 'country'], 'product_shipping_product_variant_id_country_unique'));
            }
            if (! $this->hasIndex('product_shipping', 'product_shipping_product_id_country_unique')) {
                Schema::table('product_shipping', fn (Blueprint $t) => $t->unique(['product_id', 'country'], 'product_shipping_product_id_country_unique'));
            }
            if ($fk) {
                Schema::table('product_shipping', function (Blueprint $t) {
                    $t->foreign('product_variant_id')
                        ->references('id')->on('product_variants')
                        ->cascadeOnDelete();
                });
            }
        }
        if (Schema::hasTable('product_variants') && $this->hasIndex('product_variants', 'product_variants_product_ext_deleted_unique')) {
            Schema::table('product_variants', fn (Blueprint $t) => $t->dropUnique('product_variants_product_ext_deleted_unique'));
        }
        foreach (['designs', 'product_variants', 'product_shipping'] as $tbl) {
            if (Schema::hasTable($tbl) && Schema::hasColumn($tbl, 'deleted_at')) {
                Schema::table($tbl, fn (Blueprint $t) => $t->dropSoftDeletes());
            }
        }
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'is_admin')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_admin'));
        }
    }

    /** 探测索引是否存在（不依赖 doctrine/dbal） */
    private function hasIndex(string $table, string $index): bool
    {
        $rows = DB::select('SHOW INDEX FROM `' . $table . '` WHERE Key_name = ?', [$index]);

        return count($rows) > 0;
    }

    /** 找某列上的外键约束名（返回 null 表示无） */
    private function foreignKeyOn(string $table, string $column): ?string
    {
        $rows = DB::select(
            'SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1',
            [$table, $column]
        );

        return $rows ? $rows[0]->CONSTRAINT_NAME : null;
    }
};
