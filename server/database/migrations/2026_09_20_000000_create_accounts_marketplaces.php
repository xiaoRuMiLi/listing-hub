<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 账号/站点（供 listings FK 引用；须先于 listings 建） */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name', 128);
            $t->string('platform', 24)->default('amazon');
            $t->string('seller_id', 64)->nullable();
            $t->string('region', 16)->nullable();
            $t->enum('status', ['active', 'disabled'])->default('active');
            $t->string('notes', 255)->nullable();
            $t->timestamps();
        });

        Schema::create('marketplaces', function (Blueprint $t) {
            $t->id();
            $t->string('platform', 24)->default('amazon');
            $t->string('code', 16);
            $t->char('country', 2)->nullable();
            $t->char('currency', 3)->nullable();
            $t->string('language', 8)->nullable();
            $t->string('domain', 64)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['platform', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplaces');
        Schema::dropIfExists('accounts');
    }
};
