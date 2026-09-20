<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 资产域（Asset）：blobs / blob_sources / assets / templates */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blobs', function (Blueprint $t) {
            $t->id();
            $t->char('sha256', 64)->unique();
            $t->string('storage_disk', 24)->default('oss');
            $t->string('storage_key', 512);
            $t->string('mime', 64)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->string('public_url', 1024)->nullable();
            $t->enum('source_type', ['local', 'remote'])->nullable();
            $t->string('origin_url', 1024)->nullable();
            $t->char('phash', 16)->nullable();
            $t->unsignedInteger('ref_count')->default(0);
            $t->timestamp('created_at')->nullable();
            $t->index('phash');
            $t->index('ref_count');
        });

        Schema::create('blob_sources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('blob_id')->constrained()->cascadeOnDelete();
            $t->char('url_hash', 64)->unique();
            $t->string('url', 1024);
            $t->timestamp('fetched_at')->nullable();
        });

        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('blob_id')->constrained();
            $t->string('owner_type', 32);
            $t->unsignedBigInteger('owner_id');
            $t->enum('media_type', ['image', 'video'])->default('image');
            $t->string('role', 32);
            $t->unsignedSmallInteger('position')->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedInteger('duration_ms')->nullable();
            $t->unsignedBigInteger('poster_blob_id')->nullable();
            $t->enum('status', ['uploaded', 'verified', 'failed'])->default('uploaded');
            $t->timestamps();
            $t->index(['owner_type', 'owner_id']);
            $t->index(['media_type', 'role']);
            $t->unique(['owner_type', 'owner_id', 'role', 'position']);
        });

        Schema::create('templates', function (Blueprint $t) {
            $t->id();
            $t->enum('kind', ['layout', 'amazon_xlsm', 'other'])->default('layout');
            $t->string('platform', 24)->nullable();
            $t->string('product_type', 40)->nullable();
            $t->string('category', 64)->nullable();
            $t->string('subcategory', 64)->nullable();
            $t->decimal('aspect', 8, 4)->nullable();
            $t->string('name', 128);
            $t->json('spec_json')->nullable();
            $t->unsignedBigInteger('blob_id')->nullable();
            $t->unsignedInteger('uses')->default(0);
            $t->enum('status', ['active', 'disabled'])->default('active');
            $t->string('created_by', 64)->nullable();
            $t->timestamps();
            $t->unique(['kind', 'platform', 'name']);
            $t->index(['category', 'subcategory']);
            $t->index('product_type');
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('blob_sources');
        Schema::dropIfExists('blobs');
    }
};
