<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capacity_profiles_v2', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_slug', 80)->default('ikast');
            $table->string('name', 120);
            $table->string('staffing_level', 24);
            $table->unsignedSmallInteger('slot_interval_minutes')->default(20);
            $table->unsignedSmallInteger('public_capacity_per_start')->default(1);
            $table->boolean('auto_buffer_enabled')->default(false);
            $table->unsignedSmallInteger('open_slots_per_cycle')->default(0);
            $table->unsignedSmallInteger('buffer_slots_per_cycle')->default(0);
            $table->boolean('buffer_internal_bookable')->default(true);
            $table->boolean('suggest_buffer_move')->default(true);
            $table->string('buffer_move_direction', 24)->default('LATER_FIRST');
            $table->unsignedSmallInteger('max_buffer_move_minutes')->default(180);
            $table->boolean('reset_pattern_after_closure')->default(true);
            $table->string('release_mode', 24)->default('MANUAL');
            $table->unsignedSmallInteger('release_minutes_before')->nullable();
            $table->json('weekdays')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->unsignedSmallInteger('priority')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['location_slug', 'staffing_level', 'active'], 'capacity_v2_profile_lookup');
        });

        Schema::create('recurring_buffer_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_slug', 80)->default('ikast');
            $table->string('name', 160);
            $table->json('weekdays');
            $table->time('start_local_time');
            $table->unsignedSmallInteger('duration_minutes')->default(20);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('internal_bookable')->default(true);
            $table->boolean('active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['location_slug', 'active']);
        });

        Schema::create('schedule_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_slug', 80)->default('ikast');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('override_type', 40);
            $table->string('buffer_type', 24)->nullable();
            $table->string('reason', 255);
            $table->boolean('locked')->default(true);
            $table->foreignId('source_rule_id')->nullable()->constrained('recurring_buffer_rules')->nullOnDelete();
            $table->foreignId('related_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->uuid('relocation_group_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['location_slug', 'starts_at', 'ends_at'], 'capacity_v2_override_lookup');
        });

        Schema::create('buffer_relocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_slug', 80)->default('ikast');
            $table->dateTime('source_starts_at');
            $table->dateTime('target_starts_at');
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('ACTIVE');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index(['department_id', 'starts_at', 'status'], 'booking_department_slot_lookup');
        });

        $ikastId = Schema::hasTable('departments') ? DB::table('departments')->where('name', 'Ikast')->value('id') : null;
        if ($ikastId) {
            DB::table('bookings')->whereNull('department_id')->update(['department_id' => $ikastId]);
            DB::table('employee_work_rules')->whereNull('department_id')->update(['department_id' => $ikastId]);
            DB::table('employees')->get()->each(function ($employee) use ($ikastId) {
                DB::table('employee_departments')->insertOrIgnore(['employee_id' => $employee->id, 'department_id' => $ikastId, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
            });
        }

        $profiles = [
            ['name' => 'Ingen medarbejdere', 'staffing_level' => 'ZERO', 'auto_buffer_enabled' => false, 'open_slots_per_cycle' => 0, 'buffer_slots_per_cycle' => 0],
            ['name' => '1 medarbejder – 3+1', 'staffing_level' => 'ONE', 'auto_buffer_enabled' => true, 'open_slots_per_cycle' => 3, 'buffer_slots_per_cycle' => 1],
            ['name' => '2+ medarbejdere', 'staffing_level' => 'TWO_PLUS', 'auto_buffer_enabled' => false, 'open_slots_per_cycle' => 0, 'buffer_slots_per_cycle' => 0],
        ];
        foreach ($profiles as $profile) {
            DB::table('capacity_profiles_v2')->insert([
                ...$profile, 'department_id' => $ikastId, 'location_slug' => 'ikast',
                'slot_interval_minutes' => 20, 'public_capacity_per_start' => 1,
                'buffer_internal_bookable' => true, 'suggest_buffer_move' => true,
                'buffer_move_direction' => 'LATER_FIRST', 'max_buffer_move_minutes' => 180,
                'reset_pattern_after_closure' => true, 'release_mode' => 'MANUAL',
                'priority' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('booking_department_slot_lookup');
            $table->dropConstrainedForeignId('department_id');
        });
        Schema::dropIfExists('buffer_relocations');
        Schema::dropIfExists('schedule_overrides');
        Schema::dropIfExists('recurring_buffer_rules');
        Schema::dropIfExists('capacity_profiles_v2');
    }
};
