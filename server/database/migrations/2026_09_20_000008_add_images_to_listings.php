<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** listings 存图片列（供"一键换图床"与展示） */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $t) {
            $t->string('main_image', 1024)->nullable()->after('customization_json');
            $t->text('other_images')->nullable()->after('main_image');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $t) {
            $t->dropColumn(['main_image', 'other_images']);
        });
    }
};
