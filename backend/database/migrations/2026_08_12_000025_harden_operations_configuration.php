<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_login_at')->nullable()->after('remember_token');
            });
        }

        foreach (range(1, 5) as $weekday) {
            $hasOpeningHours = DB::table('availability_rules')->where('weekday', $weekday)->where('kind', 'opening_hours')->exists();
            $hasBreak = DB::table('availability_rules')->where('weekday', $weekday)->where('kind', 'break')->exists();
            if ($hasOpeningHours && ! $hasBreak) {
                DB::table('availability_rules')->insert([
                    'kind' => 'break', 'weekday' => $weekday,
                    'starts_at' => '12:20', 'ends_at' => '13:00',
                    'label' => 'Frokostpause', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('availability_rules')->where('kind', 'break')->where('label', 'Frokostpause')->delete();
        if (Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('last_login_at'));
        }
    }
};
