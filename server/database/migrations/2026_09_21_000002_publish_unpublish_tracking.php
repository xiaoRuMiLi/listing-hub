<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 上架/下架回写支持（2026-09-21）
 *
 * - listings 增：unpublished_at / unpublish_reason / last_action_by / last_action_at
 *   用于记录"下架时间 / 原因 / 最后操作人"，与既有 published_at 配套。
 * - 幂等：每列加前先 hasColumn 判断（MySQL DDL 不可回滚，失败重跑会撞列）。
 */
return new class extends Migration
{
    private function addCol(string $table, string $col, \Closure $def): void
    {
        if (Schema::hasColumn($table, $col)) { return; }
        Schema::table($table, function (Blueprint $t) use ($def) { $def($t); });
    }

    public function up(): void
    {
        $this->addCol('listings', 'unpublished_at', fn ($t) => $t->timestamp('unpublished_at')->nullable());
        $this->addCol('listings', 'unpublish_reason', fn ($t) => $t->string('unpublish_reason', 255)->nullable());
        // 最后动作留痕（上架/下架通用）：谁、何时
        $this->addCol('listings', 'last_action', fn ($t) => $t->string('last_action', 24)->nullable());
        $this->addCol('listings', 'last_action_by', fn ($t) => $t->string('last_action_by', 64)->nullable());
        $this->addCol('listings', 'last_action_at', fn ($t) => $t->timestamp('last_action_at')->nullable());
    }

    public function down(): void
    {
        foreach (['unpublished_at', 'unpublish_reason', 'last_action', 'last_action_by', 'last_action_at'] as $c) {
            if (Schema::hasColumn('listings', $c)) {
                Schema::table('listings', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
