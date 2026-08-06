<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('description')->nullable();
            $table->string('category', 80);
            $table->string('publisher')->nullable();
            $table->string('version')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->string('status', 32)->default('processing');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('access_level', 32)->default('employee');
            $table->boolean('is_active')->default(true);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('replaces_document_id')->nullable()->constrained('ai_documents')->nullOnDelete();
            $table->text('processing_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'is_active', 'category']);
        });

        Schema::create('ai_document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('ai_documents')->cascadeOnDelete();
            $table->unsignedInteger('page_number')->nullable();
            $table->string('section_title')->nullable();
            $table->longText('content');
            $table->json('embedding')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'page_number']);
        });

        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->boolean('is_shared')->default(false);
            $table->timestamps();
            $table->index(['created_by', 'updated_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role', 16);
            $table->longText('content');
            $table->string('model')->nullable();
            $table->string('confidence', 24)->default('not_assessed');
            $table->json('provider_metadata')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('ai_message_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('ai_messages')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('ai_documents')->cascadeOnDelete();
            $table->foreignId('chunk_id')->nullable()->constrained('ai_document_chunks')->nullOnDelete();
            $table->unsignedInteger('page_number')->nullable();
            $table->text('quotation')->nullable();
            $table->decimal('relevance_score', 6, 5)->nullable();
            $table->timestamps();
        });

        Schema::create('investigations', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique();
            $table->string('title');
            $table->text('description');
            $table->string('status', 40)->default('Ny');
            $table->longText('conclusion')->nullable();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('follow_up_date')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
        });

        Schema::create('investigation_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investigation_id')->constrained('investigations')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('priority', 20)->default('normal');
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('arvo_task_id')->nullable()->unique();
            $table->string('arvo_task_url')->nullable();
            $table->timestamp('arvo_updated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_tasks');
        Schema::dropIfExists('investigations');
        Schema::dropIfExists('ai_message_sources');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_document_chunks');
        Schema::dropIfExists('ai_documents');
    }
};
