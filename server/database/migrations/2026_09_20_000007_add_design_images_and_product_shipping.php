<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ① designs 补效果图列（main_image / other_images）
 * ② product_shipping 支持"商品级"（加 product_id；variant_id 可空）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('designs', function (Blueprint $t) {
            $t->string('main_image', 1024)->nullable()->after('gallery_codes');
            $t->text('other_images')->nullable()->after('main_image');
        });

        Schema::table('product_shipping', function (Blueprint $t) {
            $t->unsignedBigInteger('product_id')->nullable()->after('id');
            $t->unsignedBigInteger('product_variant_id')->nullable()->change();
            $t->index('product_id');
            $t->unique(['product_id', 'country']);
        });
    }

    public function down(): void
    {
        Schema::table('designs', function (Blueprint $t) {
            $t->dropColumn(['main_image', 'other_images']);
        });
        Schema::table('product_shipping', function (Blueprint $t) {
            $t->dropIndex(['product_id']);
            $t->dropUnique(['product_id', 'country']);
            $t->dropColumn('product_id');
        });
    }
};
