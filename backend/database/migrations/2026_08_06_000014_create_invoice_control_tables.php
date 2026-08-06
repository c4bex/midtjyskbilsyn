<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_drafts', function (Blueprint $table) {
            $table->date('period_start')->nullable()->after('period');
            $table->date('period_end')->nullable()->after('period_start');
            $table->string('billing_method', 32)->default('email')->after('status');
            $table->string('payment_terms', 32)->nullable()->after('billing_method');
            $table->string('external_status', 32)->default('NOT_SENT')->after('payment_terms');
            $table->boolean('requires_action')->default(false)->after('external_status');
            $table->unsignedBigInteger('approved_by')->nullable()->after('requires_action');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->timestamp('locked_at')->nullable()->after('approved_at');
        });

        Schema::create('invoice_periods', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 24)->default('OPEN');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->timestamps();
            $table->unique(['period_start', 'period_end']);
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_draft_id')->constrained('invoice_drafts')->cascadeOnDelete();
            $table->string('source_system', 40)->default('manual');
            $table->string('source_key', 190)->unique();
            $table->json('source_payload')->nullable();
            $table->text('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit', 16)->default('stk.');
            $table->unsignedInteger('unit_price_ore');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(25);
            $table->string('status', 24)->default('included');
            $table->text('original_description')->nullable();
            $table->unsignedInteger('original_unit_price_ore')->nullable();
            $table->timestamps();
            $table->index(['invoice_draft_id', 'status']);
        });

        Schema::create('invoice_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_draft_id')->constrained('invoice_drafts')->cascadeOnDelete();
            $table->foreignId('invoice_line_id')->nullable()->constrained('invoice_lines')->nullOnDelete();
            $table->string('field', 64);
            $table->text('original_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $legacy = DB::table('invoice_drafts')->get();
        foreach ($legacy as $draft) {
            $periodStart = $draft->period_start ?: (strtolower($draft->period) === 'juli 2026' ? '2026-07-01' : now()->startOfMonth()->toDateString());
            $periodEnd = $draft->period_end ?: date('Y-m-t', strtotime($periodStart));
            DB::table('invoice_drafts')->where('id', $draft->id)->update(['period_start' => $periodStart, 'period_end' => $periodEnd]);
            DB::table('invoice_lines')->insertOrIgnore([
                'invoice_draft_id' => $draft->id, 'source_system' => 'legacy', 'source_key' => 'legacy:invoice-draft:'.$draft->id,
                'description' => $draft->description, 'quantity' => $draft->quantity, 'unit' => 'stk.', 'unit_price_ore' => $draft->unit_price_ore,
                'original_description' => $draft->description, 'original_unit_price_ore' => $draft->unit_price_ore,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_revisions');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoice_periods');
        Schema::table('invoice_drafts', function (Blueprint $table) {
            $table->dropColumn(['period_start', 'period_end', 'billing_method', 'payment_terms', 'external_status', 'requires_action', 'approved_by', 'approved_at', 'locked_at']);
        });
    }
};
