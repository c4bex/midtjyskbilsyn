<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('dedupe_key')->unique();
            $table->string('category', 32);
            $table->string('severity', 16)->default('info');
            $table->string('title');
            $table->text('message');
            $table->string('action_view', 32)->nullable();
            $table->string('action_label')->nullable();
            $table->json('action_data')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('system_notification_user_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('system_notifications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_notification_user_states');
        Schema::dropIfExists('system_notifications');
    }
};
