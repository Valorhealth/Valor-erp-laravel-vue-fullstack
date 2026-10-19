<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->bigIncrements('notification_id');
            $table->integer('user_id');
            $table->string('category', 50)->default('workflow');
            $table->string('title', 500);
            $table->text('body')->nullable();
            $table->string('target_type', 100)->nullable();
            $table->string('target_id', 100)->nullable();
            $table->string('action_url', 1000)->nullable();
            $table->string('dedupe_key', 64)->unique();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->index(['user_id', 'read_at', 'created_at'], 'user_notifications_inbox_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
