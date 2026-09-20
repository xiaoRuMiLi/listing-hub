<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商品域（Catalog）：供应商 / 商品 / 来源对应 / 分类 / 变体 / 物流
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32)->unique();
            $t->string('name', 128);
            $t->string('base_url', 255)->nullable();
            $t->enum('status', ['active', 'disabled'])->default('active');
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('code', 64)->nullable()->unique();
            $t->string('cn_name', 255)->nullable();
            $t->string('en_name', 255)->nullable();
            $t->string('material_cn', 64)->nullable();
            $t->string('material_en', 64)->nullable();
            $t->decimal('print_face_w', 10, 2)->nullable();
            $t->decimal('print_face_h', 10, 2)->nullable();
            $t->decimal('min_price', 12, 2)->nullable();
            $t->char('currency', 3)->nullable();
            $t->enum('status', ['draft', 'ready', 'synced', 'archived'])->default('draft');
            $t->json('detail_json')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index('status');
        });

        Schema::create('product_suppliers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('supplier_id')->constrained();
            $t->string('external_id', 64);
            $t->string('supplier_sku', 64)->nullable();
            $t->json('cost_json')->nullable();
            $t->boolean('is_primary')->default(false);
            $t->string('status', 24)->nullable();
            $t->timestamps();
            $t->unique(['supplier_id', 'external_id']);
            $t->unique(['product_id', 'supplier_id']);
        });

        Schema::create('categories', function (Blueprint $t) {
            $t->id();
            $t->string('platform', 24);
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('code', 96);
            $t->string('name_cn', 128)->nullable();
            $t->string('name_en', 128)->nullable();
            $t->string('path', 512)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['platform', 'code']);
            $t->index('parent_id');
        });

        Schema::create('product_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('category_id')->constrained()->cascadeOnDelete();
            $t->string('platform', 24);
            $t->boolean('is_primary')->default(false);
            $t->timestamp('created_at')->nullable();
            $t->unique(['product_id', 'category_id']);
            $t->index('category_id');
            $t->index('platform');
        });

        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('product_supplier_id')->nullable();
            $t->string('external_variant_id', 64)->nullable();
            $t->json('spec_json')->nullable();
            $t->string('color', 32)->nullable();
            $t->string('size', 32)->nullable();
            $t->json('cost_json')->nullable();
            $t->unsignedInteger('weight_g')->nullable();
            $t->decimal('size_l_cm', 10, 2)->nullable();
            $t->decimal('size_w_cm', 10, 2)->nullable();
            $t->decimal('size_h_cm', 10, 2)->nullable();
            $t->decimal('pkg_l_cm', 10, 2)->nullable();
            $t->decimal('pkg_w_cm', 10, 2)->nullable();
            $t->decimal('pkg_h_cm', 10, 2)->nullable();
            $t->decimal('volume_cm3', 12, 2)->nullable();
            $t->string('status', 24)->nullable();
            $t->timestamps();
            $t->index('product_id');
            $t->index(['product_id', 'color']);
            $t->index(['product_id', 'size']);
        });

        Schema::create('product_shipping', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $t->char('country', 2);
            $t->decimal('amount', 12, 2)->nullable();
            $t->char('currency', 3)->nullable();
            $t->string('channel', 64)->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->unique(['product_variant_id', 'country']);
            $t->index('country');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_shipping');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('product_suppliers');
        Schema::dropIfExists('products');
        Schema::dropIfExists('suppliers');
    }
};
