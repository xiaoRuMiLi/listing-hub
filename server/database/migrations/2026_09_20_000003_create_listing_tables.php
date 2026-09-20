<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 上架域（Listing）：listings / listing_revisions / field_definitions */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained();
            $t->string('platform', 24)->default('amazon');
            $t->string('marketplace', 16);
            $t->foreignId('product_id')->constrained();
            $t->unsignedBigInteger('design_id')->nullable();
            $t->string('sku', 64);
            $t->string('parent_sku', 64)->nullable();
            $t->boolean('is_parent')->default(true);
            $t->string('variation_theme', 24)->nullable();
            $t->string('variant_color', 32)->nullable();
            $t->string('variant_size', 16)->nullable();
            $t->string('amazon_product_type', 40)->nullable();
            $t->enum('status', ['draft', 'candidate', 'ready', 'published', 'error', 'archived'])->default('draft');
            $t->decimal('price', 12, 2)->nullable();
            $t->decimal('product_price', 12, 2)->nullable();
            $t->decimal('shipping_fee', 12, 2)->nullable();
            $t->char('currency', 3)->nullable();
            $t->unsignedInteger('quantity')->nullable();
            $t->string('asin', 16)->nullable();
            $t->timestamp('first_published_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->boolean('is_custom')->default(true);
            $t->json('customization_json')->nullable();
            $t->unsignedInteger('revision')->default(1);
            $t->json('attrs_json')->nullable();
            $t->boolean('is_complete')->default(false);
            $t->json('missing_fields')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['account_id', 'platform', 'marketplace', 'sku']);
            $t->index(['platform', 'marketplace', 'status']);
            $t->index('product_id');
            $t->index('parent_sku');
            $t->index('updated_at');
            $t->index('first_published_at');
        });

        Schema::create('listing_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('revision');
            $t->json('snapshot_json');
            $t->json('changed_fields')->nullable();
            $t->string('source', 24)->nullable();
            $t->string('author', 64)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['listing_id', 'revision']);
        });

        Schema::create('field_definitions', function (Blueprint $t) {
            $t->id();
            $t->string('platform', 24)->default('amazon');
            $t->string('product_type', 40);
            $t->string('field_key', 80);
            $t->string('group_name', 64)->nullable();
            $t->string('display_name', 128)->nullable();
            $t->string('data_type', 24)->nullable();
            $t->json('required_rule')->nullable();
            $t->json('enum_values')->nullable();
            $t->string('source', 24)->nullable();
            $t->boolean('is_media')->default(false);
            $t->string('media_role', 24)->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->unique(['platform', 'product_type', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_definitions');
        Schema::dropIfExists('listing_revisions');
        Schema::dropIfExists('listings');
    }
};
