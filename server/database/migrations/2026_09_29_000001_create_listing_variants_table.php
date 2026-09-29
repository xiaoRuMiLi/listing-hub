<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 变体子体独立表（对齐本地 listing_variants.csv）
 *
 * 背景（VARIANTS-SYNC-DESIGN.md）：
 *  - 本地「多规格物流费 / 多规格售价 / 父子变体关系」需可存、可拉、拉回即用。
 *  - 旧方案把子体塞进 listings 同表（is_parent=false）→ 缺 pkg_* 4 列、无逐规格 8 国价/费。
 *  - 新方案：独立 listing_variants 表 + products 商品级 8 国物流/售价物理列。
 *
 * ⚠️ 本 migration 为【幂等】：每列/每索引加前先 hasColumn/hasIndex 判断（照抄 r2_r5_gap_fields 的做法），
 *    因 MySQL DDL 不可回滚，失败重跑会撞已存在的列。
 */
return new class extends Migration
{
    /** 若列不存在才添加 */
    private function addCol(string $table, string $col, \Closure $def): void
    {
        if (Schema::hasColumn($table, $col)) { return; }
        Schema::table($table, function (Blueprint $t) use ($def) { $def($t); });
    }

    /** 若索引不存在才添加 */
    private function addIdx(string $table, string $col, ?string $name = null): void
    {
        $idx = $name ?: ($table . '_' . $col . '_index');
        try {
            $exists = collect(Schema::getIndexes($table))->contains(fn ($i) => ($i['name'] ?? '') === $idx);
        } catch (\Throwable $e) { $exists = false; }
        if ($exists) { return; }
        Schema::table($table, fn (Blueprint $t) => $t->index($col, $idx));
    }

    public function up(): void
    {
        // ============ ① 独立 listing_variants 表 ============
        if (! Schema::hasTable('listing_variants')) {
            Schema::create('listing_variants', function (Blueprint $t) {
                $t->id();

                // ★ 跨端稳定键：本地 listing_variants.row_id（一对一）
                $t->unsignedBigInteger('local_row_id')->nullable();
                $t->unsignedBigInteger('parent_local_row_id')->nullable();

                // 父体关联（双轨：FK + 冗余 SKU，二者任一可用）
                $t->unsignedBigInteger('parent_listing_id')->nullable();
                $t->string('parent_sku', 64)->nullable();

                // 归属（对齐 listings 的幂等键）
                $t->unsignedBigInteger('account_id')->nullable();
                $t->string('platform', 24)->default('amazon');
                $t->string('marketplace', 16)->default('A1F83G8C2ARO7P');
                $t->string('product_code', 64)->nullable();
                $t->unsignedBigInteger('product_id')->nullable();

                // 子体标识
                $t->string('sku', 64);
                $t->string('variation_theme', 24)->nullable();
                $t->string('variant_color', 32)->nullable();
                $t->string('variant_size', 32)->nullable();
                $t->string('variant_code', 16)->nullable();
                $t->string('variant_value', 64)->nullable();

                // 设计 / 图
                $t->string('design_code', 32)->nullable();
                $t->unsignedBigInteger('design_id')->nullable();
                $t->text('main_image')->nullable();
                $t->text('other_images')->nullable();

                // ★ 多规格售价（默认站点口径；逐国明细见 pricing_json）
                $t->decimal('price', 12, 2)->nullable();
                $t->decimal('product_price', 12, 2)->nullable();
                $t->decimal('shipping_fee', 12, 2)->nullable();
                $t->char('currency', 3)->nullable();
                $t->unsignedInteger('quantity')->nullable();

                // ★ 多规格物流费：逐规格包装尺寸/重量（本地已有、旧中台缺的 4 列）
                $t->decimal('pkg_length', 10, 2)->nullable();
                $t->decimal('pkg_width', 10, 2)->nullable();
                $t->decimal('pkg_height', 10, 2)->nullable();
                $t->decimal('pkg_weight', 10, 2)->nullable();

                // ★ 逐规格 8 国售价桶 / 物流明细桶
                $t->json('pricing_json')->nullable();
                $t->json('shipping_json')->nullable();

                // 元数据
                $t->string('status', 24)->default('planned');
                $t->string('source', 48)->nullable();
                $t->string('generated_at', 32)->nullable();
                $t->timestamp('edited_at')->nullable();
                $t->text('notes')->nullable();
                $t->string('pushed_by', 64)->nullable();
                $t->unsignedInteger('revision')->default(1);
                $t->timestamps();
                $t->softDeletes();

                $t->unique('local_row_id', 'listing_variants_local_row_id_unique');
                $t->unique(['account_id', 'platform', 'marketplace', 'sku'], 'listing_variants_biz_unique');
                $t->index('parent_sku');
                $t->index('parent_listing_id');
                $t->index('product_code');
                $t->index('updated_at');
            });
        }

        // ============ ② products 补物理列（商品级 8 国物流/售价） ============
        $ccs = ['US', 'UK', 'CA', 'DE', 'MX', 'FR', 'ES', 'IT'];
        foreach ($ccs as $cc) {
            $this->addCol('products', 'shipping_' . $cc, fn ($t) => $t->decimal('shipping_' . $cc, 12, 2)->nullable());
            $this->addCol('products', 'shipping_channel_' . $cc, fn ($t) => $t->string('shipping_channel_' . $cc, 64)->nullable());
            $this->addCol('products', 'freight_template_' . $cc, fn ($t) => $t->string('freight_template_' . $cc, 64)->nullable());
            $this->addCol('products', 'price_' . $cc, fn ($t) => $t->decimal('price_' . $cc, 12, 2)->nullable());
        }
        $this->addCol('products', 'shipping_variant', fn ($t) => $t->string('shipping_variant', 64)->nullable());
        $this->addCol('products', 'shipping_updated_at', fn ($t) => $t->timestamp('shipping_updated_at')->nullable());

        // ============ ③ 旧数据迁移：listings(is_parent=false) → listing_variants ============
        //   仅在目标表为空时执行一次，避免重跑重复插入。
        if (Schema::hasTable('listing_variants') && DB::table('listing_variants')->count() === 0) {
            $parents = DB::table('listings')
                ->where('is_parent', false)
                ->orderBy('id')
                ->get();

            foreach ($parents as $l) {
                $variantJson = json_decode((string) $l->variant_json, true);
                if (! is_array($variantJson)) { $variantJson = []; }

                $parentId = null;
                if (! empty($l->parent_sku)) {
                    $parentId = DB::table('listings')
                        ->where('account_id', $l->account_id)
                        ->where('marketplace', $l->marketplace)
                        ->where('sku', $l->parent_sku)
                        ->value('id');
                }

                $variantValue = $variantJson['variant_value'] ?? trim(trim((string) $l->variant_color) . ' ' . trim((string) $l->variant_size));

                DB::table('listing_variants')->insert([
                    'local_row_id' => null,                 // 旧数据无法还原本地 row_id
                    'parent_local_row_id' => null,
                    'parent_listing_id' => $parentId,
                    'parent_sku' => $l->parent_sku,
                    'account_id' => $l->account_id,
                    'platform' => $l->platform ?: 'amazon',
                    'marketplace' => $l->marketplace,
                    'product_code' => $l->product_code,
                    'product_id' => $l->product_id,
                    'sku' => $l->sku,
                    'variation_theme' => $l->variation_theme,
                    'variant_color' => $l->variant_color,
                    'variant_size' => $l->variant_size,
                    'variant_code' => $l->variant_code,
                    'variant_value' => $variantValue ?: null,
                    'design_code' => $l->design_code,
                    'design_id' => $l->design_id,
                    'main_image' => $l->main_image,
                    'other_images' => $l->other_images,
                    'price' => $l->price,
                    'product_price' => $l->product_price,
                    'shipping_fee' => $l->shipping_fee,
                    'currency' => $l->currency,
                    'quantity' => $l->quantity,
                    'status' => $l->status ?: 'planned',
                    'source' => $variantJson['source'] ?? null,
                    'generated_at' => $variantJson['generated_at'] ?? null,
                    'edited_at' => $l->updated_at,
                    'notes' => $variantJson['notes'] ?? null,
                    'pushed_by' => $l->pushed_by,
                    'revision' => $l->revision ?: 1,
                    'created_at' => $l->created_at,
                    'updated_at' => $l->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        // 仅回滚本 migration 新增内容（旧 listings 子体行保留不动）
        $ccs = ['US', 'UK', 'CA', 'DE', 'MX', 'FR', 'ES', 'IT'];
        foreach ($ccs as $cc) {
            foreach (['shipping_', 'shipping_channel_', 'freight_template_', 'price_'] as $p) {
                $col = $p . $cc;
                if (Schema::hasColumn('products', $col)) { Schema::table('products', fn ($t) => $t->dropColumn($col)); }
            }
        }
        foreach (['shipping_variant', 'shipping_updated_at'] as $col) {
            if (Schema::hasColumn('products', $col)) { Schema::table('products', fn ($t) => $t->dropColumn($col)); }
        }
        Schema::dropIfExists('listing_variants');
    }
};
