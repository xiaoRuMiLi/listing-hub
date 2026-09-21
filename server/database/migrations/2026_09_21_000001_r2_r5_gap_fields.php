<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R2–R5 整改（对账报告 hub-pull-gap-12670-20260921.md）：
 * 让中台各 dataset 的字段集 ⊇ 本地对应表全部列，pullcsv 可 100% 还原。
 *
 * 策略（用户确认）：
 *  - 文案/长尾字段走 JSON 桶：copy_json / profile_json / variant_json
 *  - 物理列只给「关联键 / 需索引 / 高频筛选」字段
 *  - 新增 pushed_by（记录谁推送）
 *
 * ⚠️ 本 migration 为【幂等】：每列/每索引加前先 hasColumn/hasIndex 判断。
 *    原因：首版曾因 designs.design_zh_name 已存在于基线建表脚本而中断，
 *    且 MySQL DDL 不可回滚 → 部分列已落库。幂等化后可安全重跑续跑。
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
    private function addIdx(string $table, string $col): void
    {
        $idx = $table . '_' . $col . '_index';
        try {
            $exists = collect(Schema::getIndexes($table))->contains(fn ($i) => ($i['name'] ?? '') === $idx);
        } catch (\Throwable $e) { $exists = false; }
        if ($exists) { return; }
        Schema::table($table, fn (Blueprint $t) => $t->index($col));
    }

    public function up(): void
    {
        // ============ R2 + R5：listings（母体与变体子体同表） ============
        $this->addCol('listings', 'copy_json', fn ($t) => $t->json('copy_json')->nullable());
        $this->addCol('listings', 'design_code', fn ($t) => $t->string('design_code', 32)->nullable());
        $this->addCol('listings', 'product_code', fn ($t) => $t->string('product_code', 64)->nullable());
        $this->addCol('listings', 'variant_code', fn ($t) => $t->string('variant_code', 16)->nullable());
        $this->addCol('listings', 'variant_json', fn ($t) => $t->json('variant_json')->nullable());
        $this->addCol('listings', 'parent_row_id', fn ($t) => $t->unsignedBigInteger('parent_row_id')->nullable());
        $this->addCol('listings', 'pushed_by', fn ($t) => $t->string('pushed_by', 64)->nullable());

        $this->addIdx('listings', 'design_code');
        $this->addIdx('listings', 'product_code');
        $this->addIdx('listings', 'parent_row_id');

        // ============ R3：products 补列（7 物理 + 1 桶） ============
        $this->addCol('products', 'spu_code', fn ($t) => $t->string('spu_code', 64)->nullable());
        $this->addCol('products', 'is_custom', fn ($t) => $t->boolean('is_custom')->nullable());
        $this->addCol('products', 'factory', fn ($t) => $t->string('factory', 64)->nullable());
        $this->addCol('products', 'variants_count', fn ($t) => $t->unsignedInteger('variants_count')->nullable());
        $this->addCol('products', 'weight_g', fn ($t) => $t->unsignedInteger('weight_g')->nullable());
        $this->addCol('products', 'volume_cm3', fn ($t) => $t->decimal('volume_cm3', 12, 2)->nullable());
        $this->addCol('products', 'design_face_count', fn ($t) => $t->unsignedInteger('design_face_count')->nullable());
        $this->addCol('products', 'profile_json', fn ($t) => $t->json('profile_json')->nullable());
        $this->addCol('products', 'pushed_by', fn ($t) => $t->string('pushed_by', 64)->nullable());

        // ============ R4：designs ============
        // ⚠️ design_zh_name / design_zh_tags / design_en_name / design_en_tags
        //    在基线建表脚本 2026_09_20_000002 里【已存在】→ 不需添加，只补 push/pull 映射。
        //    这里仍做幂等兜底（若某环境缺列则补上）。
        //    adjust_json 同样早已存在。
        $this->addCol('designs', 'design_zh_name', fn ($t) => $t->string('design_zh_name', 255)->nullable());
        $this->addCol('designs', 'design_zh_tags', fn ($t) => $t->string('design_zh_tags', 255)->nullable());
        $this->addCol('designs', 'design_en_name', fn ($t) => $t->string('design_en_name', 255)->nullable());
        $this->addCol('designs', 'design_en_tags', fn ($t) => $t->string('design_en_tags', 255)->nullable());
        $this->addCol('designs', 'pushed_by', fn ($t) => $t->string('pushed_by', 64)->nullable());

        // ============ R5：listings.status 枚举扩 planned ============
        // 幂等：仅当枚举里还没有 planned 时才 MODIFY（避免每次重跑都重建表）。
        $col = collect(Schema::getColumns('listings'))->firstWhere('name', 'status');
        $type = is_array($col) ? (string) ($col['type'] ?? $col['type_name'] ?? '') : '';
        if ($type !== '' && stripos($type, 'planned') === false) {
            \DB::statement("ALTER TABLE `listings` MODIFY `status` ENUM('draft','candidate','planned','ready','published','error','archived') NOT NULL DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        // 仅回滚本 migration 新增的、且可安全删除的列（designs 的 4 个 zh/en 列属基线，不删）
        Schema::table('listings', function (Blueprint $t) {
            foreach (['design_code', 'product_code', 'parent_row_id'] as $c) {
                try { $t->dropIndex($c . '_index'); } catch (\Throwable $e) { /* */ }
            }
        });
        foreach (['copy_json', 'design_code', 'product_code', 'variant_code', 'variant_json', 'parent_row_id', 'pushed_by'] as $c) {
            if (Schema::hasColumn('listings', $c)) { Schema::table('listings', fn ($t) => $t->dropColumn($c)); }
        }
        foreach (['spu_code', 'is_custom', 'factory', 'variants_count', 'weight_g', 'volume_cm3', 'design_face_count', 'profile_json', 'pushed_by'] as $c) {
            if (Schema::hasColumn('products', $c)) { Schema::table('products', fn ($t) => $t->dropColumn($c)); }
        }
        if (Schema::hasColumn('designs', 'pushed_by')) { Schema::table('designs', fn ($t) => $t->dropColumn('pushed_by')); }
    }
};
