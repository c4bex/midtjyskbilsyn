<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('capacity_profiles_v2') || ! Schema::hasTable('departments')) {
            return;
        }

        $profiles = [
            ['name' => 'Ingen medarbejdere', 'staffing_level' => 'ZERO', 'auto_buffer_enabled' => false, 'open_slots_per_cycle' => 0, 'buffer_slots_per_cycle' => 0],
            ['name' => '1 medarbejder – 3+1', 'staffing_level' => 'ONE', 'auto_buffer_enabled' => true, 'open_slots_per_cycle' => 3, 'buffer_slots_per_cycle' => 1],
            ['name' => '2+ medarbejdere', 'staffing_level' => 'TWO_PLUS', 'auto_buffer_enabled' => false, 'open_slots_per_cycle' => 0, 'buffer_slots_per_cycle' => 0],
        ];

        foreach (DB::table('departments')->where('active', true)->get() as $department) {
            $location = Str::slug($department->name);
            foreach ($profiles as $profile) {
                $exists = DB::table('capacity_profiles_v2')
                    ->where('department_id', $department->id)
                    ->where('staffing_level', $profile['staffing_level'])
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('capacity_profiles_v2')->insert([
                    ...$profile,
                    'department_id' => $department->id,
                    'location_slug' => $location,
                    'slot_interval_minutes' => 20,
                    'public_capacity_per_start' => 1,
                    'buffer_internal_bookable' => true,
                    'suggest_buffer_move' => true,
                    'buffer_move_direction' => 'LATER_FIRST',
                    'max_buffer_move_minutes' => 180,
                    'reset_pattern_after_closure' => true,
                    'release_mode' => 'MANUAL',
                    'priority' => 0,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Standardprofiler er konfiguration og slettes bevidst ikke ved rollback.
    }
};
