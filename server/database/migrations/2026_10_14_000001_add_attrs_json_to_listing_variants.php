<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-14：`listing_variants` 补 `attrs_json`（子体级【属性覆盖】，与本地 listing_variants.csv 对齐）。
 *
 * 背景：本地为支持「逐子体不同节点值」（如 recommended_browse_nodes）新增该列，
 *   此处中台同步加列，否则 push/pull 会丢该字段。
 * 格式：JSON **文本**（路径口径，与 listing_copy.attrs_json 一致）；`_` 前缀键为审阅字段。
 *   （刻意不 cast 成 array：本地 CSV 存的就是 JSON 字符串，两端保持同形，避免二次编码。）
 *
 * 幂等：hasColumn 判断，可安全重跑。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('listing_variants')) { return; }
        if (! Schema::hasColumn('listing_variants', 'attrs_json')) {
            Schema::table('listing_variants', function (Blueprint $t) {
                $t->text('attrs_json')->nullable()->after('pkg_weight');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('listing_variants') && Schema::hasColumn('listing_variants', 'attrs_json')) {
            Schema::table('listing_variants', function (Blueprint $t) {
                $t->dropColumn('attrs_json');
            });
        }
    }
};
