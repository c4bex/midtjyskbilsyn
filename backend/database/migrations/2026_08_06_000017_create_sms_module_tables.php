<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('customer_type');
        });

        Schema::table('sms_messages', function (Blueprint $table) {
            $table->string('template_code', 80)->nullable()->after('kind');
            $table->string('recipient_masked', 32)->nullable();
            $table->string('sender_id', 32)->nullable();
            $table->text('body')->nullable();
            $table->string('provider', 40)->nullable();
            $table->string('provider_message_id', 120)->nullable();
            $table->unsignedTinyInteger('segment_count')->default(1);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('sms_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('provider', 40)->default('gatewayapi');
            $table->string('sender_id', 32)->default('MB Bilsyn');
            $table->time('reminder_time')->default('15:00:00');
            $table->time('quiet_start')->default('21:00:00');
            $table->time('quiet_end')->default('07:00:00');
            $table->boolean('private_confirmation')->default(true);
            $table->boolean('private_reminder')->default(true);
            $table->boolean('private_change')->default(true);
            $table->boolean('business_enabled')->default(false);
            $table->boolean('auto_retry')->default(true);
            $table->unsignedTinyInteger('max_retry_attempts')->default(3);
            $table->timestamps();
        });

        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('audience', 24);
            $table->string('name');
            $table->text('body');
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('business_sms_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('confirmation_enabled')->default(false);
            $table->boolean('reminder_enabled')->default(false);
            $table->boolean('change_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('sms_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sms_message_id')->nullable()->constrained('sms_messages')->nullOnDelete();
            $table->string('provider_event_id', 160)->unique();
            $table->string('status', 32);
            $table->json('payload')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();
        });

        DB::table('sms_settings')->insert(['created_at' => now(), 'updated_at' => now()]);
        $templates = [
            ['PRIVATE_BOOKING_CONFIRMATION', 'private', 'Privat · bookingbekræftelse', 'Hej {{customerName}}. Din tid hos Midtjysk Bilsyn er {{date}} kl. {{time}}. Svar gerne på denne SMS ved spørgsmål.'],
            ['PRIVATE_BOOKING_REMINDER', 'private', 'Privat · påmindelse', 'Påmindelse: Du har tid hos Midtjysk Bilsyn {{date}} kl. {{time}} for {{registration}}. Vi glæder os til at se dig.'],
            ['PRIVATE_BOOKING_CHANGED', 'private', 'Privat · ændret booking', 'Din tid hos Midtjysk Bilsyn er ændret til {{date}} kl. {{time}}. Svar gerne på denne SMS ved spørgsmål.'],
            ['BUSINESS_BOOKING_CONFIRMATION', 'business', 'Erhverv · bookingbekræftelse', 'Booking hos Midtjysk Bilsyn: {{date}} kl. {{time}} · {{registration}}.'],
            ['BUSINESS_BOOKING_REMINDER', 'business', 'Erhverv · påmindelse', 'Påmindelse om booking hos Midtjysk Bilsyn {{date}} kl. {{time}} · {{registration}}.'],
            ['BUSINESS_BOOKING_CHANGED', 'business', 'Erhverv · ændret booking', 'Booking ændret hos Midtjysk Bilsyn til {{date}} kl. {{time}} · {{registration}}.'],
        ];
        foreach ($templates as [$code, $audience, $name, $body]) {
            DB::table('sms_templates')->insert(['code' => $code, 'audience' => $audience, 'name' => $name, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_events');
        Schema::dropIfExists('business_sms_preferences');
        Schema::dropIfExists('sms_templates');
        Schema::dropIfExists('sms_settings');
        Schema::table('sms_messages', function (Blueprint $table) {
            foreach (['template_code', 'recipient_masked', 'sender_id', 'body', 'provider', 'provider_message_id', 'segment_count', 'scheduled_at', 'queued_at', 'sent_at', 'delivered_at', 'failed_at', 'cancelled_at', 'error_code', 'error_message', 'retry_count'] as $column) {
                if (Schema::hasColumn('sms_messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('phone'));
    }
};
