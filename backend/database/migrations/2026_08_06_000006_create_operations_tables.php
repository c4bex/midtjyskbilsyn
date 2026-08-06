<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('availability_rules', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->date('date_from')->nullable();
            $table->date('date_to')->nullable();
            $table->string('label');
            $table->timestamps();
            $table->index(['weekday', 'kind']);
            $table->index(['date_from', 'date_to']);
        });

        Schema::create('employee_absences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->date('date_from');
            $table->date('date_to');
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'date_from', 'date_to']);
        });

        Schema::create('employee_work_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->boolean('working')->default(true);
            $table->timestamps();
            $table->unique(['employee_id', 'weekday']);
        });

        Schema::create('invoice_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('customer_name');
            $table->string('period', 32);
            $table->text('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price_ore');
            $table->string('status', 32)->default('Klargøres');
            $table->string('source_reference')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 32);
            $table->string('recipient_hash', 64)->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('idempotency_key')->unique();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('invoice_drafts');
        Schema::dropIfExists('employee_work_rules');
        Schema::dropIfExists('employee_absences');
        Schema::dropIfExists('availability_rules');
    }
};
