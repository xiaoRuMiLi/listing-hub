<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-17：`designs` 补 3 列，支持**图片双向同步**（人工 override vs 上游出图）。
 *
 * 背景（实测）：`hub:pull` 会触发 `listing:rebuild` → PullFlow 用指纹接口的 `effectImages`
 *   覆盖本地 `designs.other_images`，导致「人工删掉 2 张」在回拉后被冲回原样。
 *   根因是「图」只有一个存储位，无法区分「上游重新出图」与「人工编辑」。
 *
 * 本组列给出两个独立来源：
 *   images_override    人工编辑后的**整组图快照**（`main|other1|other2...`，含主图）
 *   images_override_at override 写入时间（ISO8601 文本）
 *   images_upstream    上游本次拉到的整组图（影子列，仅备查/对比，不参与发布）
 *
 * 口径：
 *   - `images_override` 非空 → 发布/重编译以它为准；PullFlow 不再覆盖 `main_image/other_images`
 *   - `images_override` 为空 → 跟随上游（`main_image`/`other_images`）
 *   - 三种变化（删除/替换/新增）都由「整组快照」自然表达，无需墓碑字段
 *
 * 格式：纯字符串（`|` 分隔），与本地 `designs.csv` 同形，**刻意不 cast 成 array**，
 *   避免两端二次编码不一致（与 attrs_json 的处理口径一致）。
 *
 * 幂等：逐列 hasColumn 判断，可安全重跑。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('designs')) { return; }

        Schema::table('designs', function (Blueprint $t) {
            if (! Schema::hasColumn('designs', 'images_override')) {
                $t->text('images_override')->nullable()->after('other_images');
            }
            if (! Schema::hasColumn('designs', 'images_override_at')) {
                $t->string('images_override_at', 32)->nullable()->after('images_override');
            }
            if (! Schema::hasColumn('designs', 'images_upstream')) {
                $t->text('images_upstream')->nullable()->after('images_override_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('designs')) { return; }
        Schema::table('designs', function (Blueprint $t) {
            foreach (['images_upstream', 'images_override_at', 'images_override'] as $c) {
                if (Schema::hasColumn('designs', $c)) { $t->dropColumn($c); }
            }
        });
    }
};
