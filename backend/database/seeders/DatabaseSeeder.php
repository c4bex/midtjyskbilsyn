<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $adminEmail = config('app.seed_admin_email');
        $adminPassword = config('app.seed_admin_password');
        $adminName = trim((string) config('app.seed_admin_name', 'Administrator')) ?: 'Administrator';
        $admin = null;
        if ($adminEmail && $adminPassword) {
            // Deployment runs the seeder after every migration. Existing accounts
            // must therefore never have their password reset by a deployment.
            $admin = User::firstOrCreate(['email' => $adminEmail], [
                'name' => $adminName,
                'password' => bcrypt($adminPassword),
            ]);
        }

        if ($admin && Schema::hasTable('employees')) {
            $ownerEmployee = DB::table('employees')->where('user_id', $admin->id)->first()
                ?? DB::table('employees')->where('email', $adminEmail)->first()
                ?? DB::table('employees')->where('display_name', $adminName)->first();
            if (! $ownerEmployee) {
                $nameParts = collect(preg_split('/\s+/', $adminName))->filter()->values();
                $initials = mb_strtoupper(mb_substr((string) $nameParts->first(), 0, 1).mb_substr((string) $nameParts->last(), 0, 1));
                $ownerEmployeeId = DB::table('employees')->insertGetId([
                    'user_id' => $admin->id, 'display_name' => $adminName, 'initials' => $initials,
                    'email' => $adminEmail, 'job_title' => 'Teknisk ansvarlig / Ejer',
                    'role' => 'Teknisk ansvarlig / Ejer', 'status' => 'ACTIVE',
                    'active' => true, 'booking_capacity' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $ownerEmployeeId = $ownerEmployee->id;
                // SEED_ADMIN_* is the deployment's authoritative owner account.
                // Repair both the account link and the role after migrations so a
                // stale employee record can never lock the owner out of navigation.
                DB::table('employees')->where('id', $ownerEmployeeId)->update([
                    'user_id' => $admin->id,
                    'email' => $adminEmail,
                    'role' => 'Teknisk ansvarlig / Ejer',
                    'job_title' => 'Teknisk ansvarlig / Ejer',
                    'status' => 'ACTIVE',
                    'active' => true,
                    'updated_at' => now(),
                ]);
            }
        }

        if (isset($ownerEmployeeId) && Schema::hasTable('departments') && Schema::hasTable('employee_departments')) {
            $ikast = DB::table('departments')->where('name', 'Ikast')->value('id');
            if ($ikast && ! DB::table('employee_departments')->where('employee_id', $ownerEmployeeId)->where('department_id', $ikast)->exists()) {
                DB::table('employee_departments')->insert(['employee_id' => $ownerEmployeeId, 'department_id' => $ikast, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        // Safe first-run defaults. Existing operational settings are never
        // overwritten when the deployment runs this seeder again.
        foreach ([
            [1, '08:00', '16:00'], [2, '08:00', '16:00'], [3, '08:00', '16:00'],
            [4, '08:00', '16:00'], [5, '08:00', '15:40'],
        ] as [$weekday, $start, $end]) {
            if (! DB::table('availability_rules')->where('kind', 'opening_hours')->where('weekday', $weekday)->exists()) {
                DB::table('availability_rules')->insert(['kind' => 'opening_hours', 'weekday' => $weekday, 'starts_at' => $start, 'ends_at' => $end, 'label' => 'Normal åbningstid', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        foreach ([6, 7] as $weekday) {
            if (! DB::table('availability_rules')->where('kind', 'closed_day')->where('weekday', $weekday)->exists()) {
                DB::table('availability_rules')->insert(['kind' => 'closed_day', 'weekday' => $weekday, 'label' => 'Fast lukkedag', 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        if (isset($ownerEmployeeId)) {
            foreach (range(1, 5) as $weekday) {
                if (! DB::table('employee_work_rules')->where('employee_id', $ownerEmployeeId)->where('weekday', $weekday)->exists()) {
                    $opening = DB::table('availability_rules')->where('kind', 'opening_hours')->where('weekday', $weekday)->first();
                    if ($opening) {
                        DB::table('employee_work_rules')->insert(['employee_id' => $ownerEmployeeId, 'weekday' => $weekday, 'starts_at' => $opening->starts_at, 'ends_at' => $opening->ends_at, 'working' => true, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }
        }

        if (! config('app.seed_demo_data', false)) {
            return;
        }

        $private = DB::table('customers')->updateOrInsert(
            ['external_reference' => 'demo-private-1'],
            ['display_name' => 'Maja Holm', 'customer_type' => 'private', 'updated_at' => now(), 'created_at' => now()],
        );
        $business = DB::table('customers')->updateOrInsert(
            ['external_reference' => 'demo-business-1'],
            ['display_name' => 'Jysk VVS ApS', 'customer_type' => 'business', 'updated_at' => now(), 'created_at' => now()],
        );

        $privateId = DB::table('customers')->where('external_reference', 'demo-private-1')->value('id');
        $businessId = DB::table('customers')->where('external_reference', 'demo-business-1')->value('id');
        DB::table('vehicles')->updateOrInsert(
            ['registration_normalized' => 'AB12345'],
            ['customer_id' => $privateId, 'make' => 'Volkswagen', 'model' => 'Golf', 'updated_at' => now(), 'created_at' => now()],
        );
        DB::table('vehicles')->updateOrInsert(
            ['registration_normalized' => 'CF45821'],
            ['customer_id' => $businessId, 'make' => 'Ford', 'model' => 'Transit', 'updated_at' => now(), 'created_at' => now()],
        );

        $privateVehicle = DB::table('vehicles')->where('registration_normalized', 'AB12345')->value('id');
        $businessVehicle = DB::table('vehicles')->where('registration_normalized', 'CF45821')->value('id');
        DB::table('bookings')->updateOrInsert(
            ['source_reference' => 'demo-booking-0820'],
            ['customer_id' => $privateId, 'vehicle_id' => $privateVehicle, 'starts_at' => '2026-08-04 08:20:00', 'ends_at' => '2026-08-04 08:40:00', 'inspection_type' => 'Periodisk syn', 'status' => 'confirmed', 'source' => 'demo', 'updated_at' => now(), 'created_at' => now()],
        );

        foreach ([
            [1, '08:00', '16:00'], [2, '08:00', '16:00'], [3, '08:00', '16:00'],
            [4, '08:00', '16:00'], [5, '08:00', '15:40'],
        ] as [$weekday, $start, $end]) {
            DB::table('availability_rules')->updateOrInsert(
                ['kind' => 'opening_hours', 'weekday' => $weekday],
                ['starts_at' => $start, 'ends_at' => $end, 'label' => 'Normal åbningstid', 'created_at' => now(), 'updated_at' => now()],
            );
        }
        foreach ([6, 7] as $weekday) {
            DB::table('availability_rules')->updateOrInsert(
                ['kind' => 'closed_day', 'weekday' => $weekday],
                ['label' => 'Fast lukkedag', 'created_at' => now(), 'updated_at' => now()],
            );
        }
        DB::table('availability_rules')->updateOrInsert(
            ['kind' => 'break', 'weekday' => 1],
            ['starts_at' => '12:20', 'ends_at' => '13:00', 'label' => 'Pause', 'created_at' => now(), 'updated_at' => now()],
        );

        $capacityEmployees = DB::table('employees')->where('booking_capacity', true)->pluck('id');
        foreach ($capacityEmployees as $employeeId) {
            foreach (range(1, 5) as $weekday) {
                $opening = DB::table('availability_rules')->where('kind', 'opening_hours')->where('weekday', $weekday)->first();
                if (! $opening) {
                    continue;
                }
                DB::table('employee_work_rules')->updateOrInsert(
                    ['employee_id' => $employeeId, 'weekday' => $weekday],
                    ['starts_at' => $opening->starts_at, 'ends_at' => $opening->ends_at, 'working' => true, 'created_at' => now(), 'updated_at' => now()],
                );
            }
        }

        foreach ([
            ['demo-invoice-1', 'Autogården', 'Juli 2026', 'Syn · 1. Syn / P-syn · Reg. nr. EC20464 · SUZUKI BALENO', 'Klargøres'],
            ['demo-invoice-2', 'Autohuset', 'Juli 2026', 'Syn · 1. Syn / P-syn · Reg. nr. EH67875 · OPEL Crossland X', 'Klar til Dinero'],
        ] as [$reference, $customer, $period, $description, $status]) {
            DB::table('invoice_drafts')->updateOrInsert(
                ['source_reference' => $reference],
                ['customer_name' => $customer, 'period' => $period, 'description' => $description, 'quantity' => 1, 'unit_price_ore' => 38000, 'status' => $status, 'created_at' => now(), 'updated_at' => now()],
            );
        }
        DB::table('bookings')->updateOrInsert(
            ['source_reference' => 'demo-booking-0800'],
            ['customer_id' => $businessId, 'vehicle_id' => $businessVehicle, 'starts_at' => '2026-08-04 08:00:00', 'ends_at' => '2026-08-04 08:20:00', 'inspection_type' => 'Periodisk syn', 'status' => 'completed', 'source' => 'demo', 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
