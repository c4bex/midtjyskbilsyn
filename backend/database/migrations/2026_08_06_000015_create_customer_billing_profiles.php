<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('cvr_number', 20)->nullable();
            $table->string('address')->nullable();
            $table->string('postal_code', 12)->nullable();
            $table->string('city')->nullable();
            $table->string('country', 2)->default('DK');
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('invoice_email')->nullable();
            $table->string('invoice_cc')->nullable();
            $table->string('billing_method', 24)->default('email');
            $table->string('payment_terms', 24)->default('netto_14');
            $table->string('ean_gln', 20)->nullable();
            $table->string('p_number', 20)->nullable();
            $table->string('syn_program_customer_id', 100)->nullable();
            $table->string('dinero_contact_id', 100)->nullable();
            $table->string('sync_status', 24)->default('not_connected');
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('requires_requisition')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_billing_profiles');
    }
};
