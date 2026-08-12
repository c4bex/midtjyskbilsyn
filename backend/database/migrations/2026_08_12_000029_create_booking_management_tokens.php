<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_management_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['booking_id', 'expires_at']);
        });

        DB::table('sms_templates')->insertOrIgnore([
            'code' => 'PRIVATE_BOOKING_CANCELLED',
            'audience' => 'private',
            'name' => 'Privat · afbestilt booking',
            'body' => 'Din tid hos Midtjysk Bilsyn {{date}} kl. {{time}} for {{registration}} er afbestilt.',
            'enabled' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('sms_templates')->where('code', 'PRIVATE_BOOKING_CANCELLED')->delete();
        Schema::dropIfExists('booking_management_tokens');
    }
};
