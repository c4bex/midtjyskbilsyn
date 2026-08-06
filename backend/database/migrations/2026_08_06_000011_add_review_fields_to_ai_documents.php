<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_documents', function (Blueprint $table) {
            $table->string('approval_status', 24)->default('draft')->after('status');
            $table->foreignId('approved_by')->nullable()->after('uploaded_by')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('extraction_method', 32)->nullable()->after('processing_error');
            $table->boolean('ocr_attempted')->default(false)->after('extraction_method');
            $table->char('content_hash', 64)->nullable()->after('ocr_attempted');
            $table->text('review_notes')->nullable()->after('content_hash');
            $table->timestamp('superseded_at')->nullable()->after('review_notes');
            $table->index(['approval_status', 'valid_to', 'is_active'], 'ai_documents_review_index');
        });

        DB::table('ai_documents')->where('status', 'ready')->update([
            'approval_status' => 'approved',
            'approved_at' => now(),
            'extraction_method' => 'legacy',
        ]);
    }

    public function down(): void
    {
        Schema::table('ai_documents', function (Blueprint $table) {
            $table->dropIndex('ai_documents_review_index');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approval_status', 'approved_at', 'extraction_method', 'ocr_attempted', 'content_hash', 'review_notes', 'superseded_at']);
        });
    }
};
