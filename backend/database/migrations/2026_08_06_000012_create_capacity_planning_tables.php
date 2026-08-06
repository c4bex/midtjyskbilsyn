<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedSmallInteger('slot_count')->default(1)->after('ends_at');
        });

        Schema::create('inspection_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedSmallInteger('required_slots')->default(1);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('calendar_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('description')->nullable();
            $table->time('first_booking_at')->default('08:00');
            $table->time('last_booking_at')->default('16:00');
            $table->unsignedSmallInteger('interval_minutes')->default(20);
            $table->unsignedSmallInteger('capacity_per_slot')->nullable();
            $table->json('allowed_inspection_types')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('profile_buffer_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_profile_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('label', 160);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('daily_calendar_configurations', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->foreignId('calendar_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 20)->default('manual');
            $table->string('conflict_status', 24)->default('ok');
            $table->unsignedSmallInteger('capacity_override')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('buffer_slots', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('reason', 160);
            $table->boolean('is_fixed')->default(false);
            $table->foreignId('calendar_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['date', 'starts_at', 'ends_at']);
        });

        DB::table('inspection_types')->insertOrIgnore([
            ['name' => 'Periodisk syn', 'required_slots' => 1, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Omsyn', 'required_slots' => 1, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Varebilssyn', 'required_slots' => 1, 'sort_order' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Motorcykelsyn', 'required_slots' => 1, 'sort_order' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Toldsyn', 'required_slots' => 2, 'sort_order' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('calendar_profiles')->insertOrIgnore([
            ['name' => 'Én medarbejder', 'description' => 'Reduceret kapacitet ved én medarbejder.', 'capacity_per_slot' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'To medarbejdere', 'description' => 'Fuld kapacitet ved to medarbejdere.', 'capacity_per_slot' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Manuel plan', 'description' => 'Bevar dagens beregnede kapacitet uden profil.', 'capacity_per_slot' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('buffer_slots');
        Schema::dropIfExists('daily_calendar_configurations');
        Schema::dropIfExists('profile_buffer_rules');
        Schema::dropIfExists('calendar_profiles');
        Schema::dropIfExists('inspection_types');
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('slot_count');
        });
    }
};
