<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE bookings ADD COLUMN active_starts_at DATETIME GENERATED ALWAYS AS (CASE WHEN status NOT IN ('cancelled', 'no_show') THEN starts_at ELSE NULL END) STORED");
            DB::statement('CREATE UNIQUE INDEX uidx_bookings_starts_active ON bookings (active_starts_at)');
            return;
        }
        DB::statement("CREATE UNIQUE INDEX uidx_bookings_starts_active ON bookings (starts_at) WHERE status NOT IN ('cancelled', 'no_show')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX uidx_bookings_starts_active'.(DB::getDriverName() === 'mysql' ? ' ON bookings' : ''));
        if (DB::getDriverName() === 'mysql') DB::statement('ALTER TABLE bookings DROP COLUMN active_starts_at');
    }
};
