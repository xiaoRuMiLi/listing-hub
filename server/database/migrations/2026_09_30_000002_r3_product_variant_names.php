<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R3b（2026-09-29）：`product_variants` 补 `size_name` / `color_name`。
 *
 * 背景：子体（listing_variants）的 `pkg_*` 大面积为空（中台 54/54 空），
 *  而包装的**物理来源**是规格层 `product_variants`。要在中台侧「拉取即用」地兜底 derive，
 *  必须能按**尺寸名**把子体 ↔ 规格对上；而 `product_variants.size` 存的是 sizeId（数字），无名字 → 补两列。
 *
 * 幂等：hasColumn 判断，可安全重跑。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_variants')) { return; }
        $addSize = ! Schema::hasColumn('product_variants', 'size_name');
        $addColor = ! Schema::hasColumn('product_variants', 'color_name');
        Schema::table('product_variants', function (Blueprint $t) use ($addSize, $addColor) {
            if ($addSize) { $t->string('size_name', 64)->nullable()->after('size'); }
            if ($addColor) { $t->string('color_name', 64)->nullable()->after('color'); }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_variants')) { return; }
        Schema::table('product_variants', function (Blueprint $t) {
            if (Schema::hasColumn('product_variants', 'color_name')) { $t->dropColumn('color_name'); }
            if (Schema::hasColumn('product_variants', 'size_name')) { $t->dropColumn('size_name'); }
        });
    }
};
