<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 设计域（Design）：designs */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('designs', function (Blueprint $t) {
            $t->id();
            $t->string('design_code', 32)->unique();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('design_key', 64)->nullable();
            $t->string('version', 16)->nullable();
            $t->string('parent_code', 32)->nullable();
            $t->string('source', 24)->nullable();
            $t->json('adjust_json')->nullable();
            $t->string('cn_name', 255)->nullable();
            $t->string('en_name', 255)->nullable();
            $t->string('design_zh_name', 255)->nullable();
            $t->string('design_zh_tags', 255)->nullable();
            $t->string('design_en_name', 255)->nullable();
            $t->string('design_en_tags', 255)->nullable();
            $t->string('pattern', 512)->nullable();
            $t->string('template', 64)->nullable();
            $t->string('gallery_codes', 255)->nullable();
            $t->unsignedInteger('effect_count')->nullable();
            $t->string('status', 24)->default('draft');
            $t->string('notes', 255)->nullable();
            $t->timestamps();
            $t->index(['product_id', 'design_key']);
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('designs');
    }
};
