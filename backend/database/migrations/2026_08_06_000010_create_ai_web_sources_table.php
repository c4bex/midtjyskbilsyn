<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_web_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('ai_messages')->cascadeOnDelete();
            $table->string('title');
            $table->text('url');
            $table->string('domain');
            $table->string('source_type', 32)->default('official_web');
            $table->timestamp('accessed_at');
            $table->timestamps();
            $table->index(['message_id', 'domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_web_sources');
    }
};
