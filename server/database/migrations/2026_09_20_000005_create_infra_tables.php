<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 基础设施：sync_jobs / outbox_events / settings */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('machine_id', 64)->nullable();
            $t->enum('direction', ['push', 'pull']);
            $t->string('scope', 128)->nullable();
            $t->string('cursor', 64)->nullable();
            $t->string('status', 24)->default('running');
            $t->json('stats_json')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->index('machine_id');
            $t->index('direction');
        });

        Schema::create('outbox_events', function (Blueprint $t) {
            $t->id();
            $t->string('type', 64);
            $t->json('payload_json')->nullable();
            $t->enum('status', ['pending', 'done', 'failed'])->default('pending');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->index(['status', 'type']);
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('group', 32);
            $t->string('key', 64);
            $t->json('value_json')->nullable();
            $t->string('description', 255)->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamp('updated_at')->nullable();
            $t->unique(['group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('sync_jobs');
    }
};
