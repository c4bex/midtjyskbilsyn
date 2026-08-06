<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_portal_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->string('customer_number', 40)->nullable();
            $table->string('default_department', 120)->nullable();
            $table->json('allowed_departments')->nullable();
            $table->json('allowed_inspection_types')->nullable();
            $table->boolean('portal_active')->default(false);
            $table->boolean('sms_active')->default(false);
            $table->enum('requisition_requirement', ['hidden', 'optional', 'required'])->default('optional');
            $table->unsignedSmallInteger('change_cutoff_minutes')->default(120);
            $table->unsignedSmallInteger('booking_horizon_days')->default(90);
            $table->timestamps();
        });

        Schema::create('business_portal_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('email')->unique();
            $table->string('phone', 32)->nullable();
            $table->string('password');
            $table->enum('role', ['admin', 'employee', 'read_only'])->default('employee');
            $table->boolean('active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->index(['customer_id', 'active']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('business_customer_id')->nullable()->after('customer_id')->constrained('customers')->nullOnDelete();
            $table->foreignId('business_portal_user_id')->nullable()->after('business_customer_id')->constrained('business_portal_users')->nullOnDelete();
            $table->string('booking_channel', 32)->default('internal')->after('source');
            $table->string('contact_name', 160)->nullable();
            $table->text('customer_note')->nullable();
            $table->text('internal_note')->nullable();
            $table->foreignId('copied_from_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->index(['business_customer_id', 'starts_at']);
            $table->index(['booking_channel', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['copied_from_booking_id']);
            $table->dropForeign(['business_portal_user_id']);
            $table->dropForeign(['business_customer_id']);
            $table->dropColumn(['business_customer_id', 'business_portal_user_id', 'booking_channel', 'contact_name', 'customer_note', 'internal_note', 'copied_from_booking_id']);
        });
        Schema::dropIfExists('business_portal_users');
        Schema::dropIfExists('business_portal_settings');
    }
};
