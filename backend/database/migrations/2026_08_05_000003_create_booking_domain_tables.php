<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('display_name');
            $table->enum('customer_type', ['private', 'business']);
            $table->string('external_reference')->nullable()->unique();
            $table->timestamps();
        });
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('registration_normalized', 12)->unique();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->timestamps();
        });
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('inspection_type');
            $table->string('status')->default('confirmed');
            $table->string('source')->default('manual');
            $table->string('source_reference')->nullable()->index();
            $table->timestamps();
            $table->index(['starts_at', 'status']);
        });
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->string('entity_type');
            $table->string('entity_id');
            $table->string('actor_id')->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->timestamps();
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('customers');
    }
};
