<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('capacity_profiles_v2')->update([
            'suggest_buffer_move' => false,
            'reset_pattern_after_closure' => false,
            'updated_at' => now(),
        ]);

        // Flytninger fra den tidligere funktion må ikke fortsætte med at ændre
        // kalenderen. De oprindelige buffertider beregnes nu altid på fast plads.
        DB::table('schedule_overrides')->where('buffer_type', 'MOVED')->delete();
        DB::table('buffer_relocations')->where('status', 'ACTIVE')->update([
            'status' => 'RESTORED',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('capacity_profiles_v2')->update([
            'suggest_buffer_move' => true,
            'reset_pattern_after_closure' => true,
            'updated_at' => now(),
        ]);
    }
};
