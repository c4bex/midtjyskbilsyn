<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->boolean('booking_capacity')->default(false)->after('active');
        });

        DB::table('employees')
            ->whereIn('role', ['Synsinspektør', 'Teknisk ansvarlig / Ejer'])
            ->update(['booking_capacity' => true, 'updated_at' => now()]);

        $employees = DB::table('employees')->where('booking_capacity', true)->pluck('id');
        foreach ($employees as $employeeId) {
            foreach (range(1, 5) as $weekday) {
                $opening = DB::table('availability_rules')
                    ->where('kind', 'opening_hours')
                    ->where('weekday', $weekday)
                    ->first();
                if (!$opening) continue;
                DB::table('employee_work_rules')->updateOrInsert(
                    ['employee_id' => $employeeId, 'weekday' => $weekday],
                    ['starts_at' => $opening->starts_at, 'ends_at' => $opening->ends_at, 'working' => true, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('DROP INDEX uidx_bookings_starts_active ON bookings');
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropColumn('active_starts_at');
            });
        } else {
            DB::statement('DROP INDEX IF EXISTS uidx_bookings_starts_active');
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('booking_capacity');
        });
    }
};
