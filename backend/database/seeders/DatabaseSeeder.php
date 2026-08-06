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
        $adminEmail = env('SEED_ADMIN_EMAIL');
        $adminPassword = env('SEED_ADMIN_PASSWORD');
        $admin = null;
        if ($adminEmail && $adminPassword) {
            $admin = User::updateOrCreate(['email' => $adminEmail], [
                'name' => 'Rasmus Havn Mouritzen',
                'password' => bcrypt($adminPassword),
            ]);
        }

        foreach ([
            ['Peter Hartz Jensen', 'Synsinspektør'],
            ['Rasmus Havn Mouritzen', 'Teknisk ansvarlig / Ejer'],
            ['Pernille Havn Mouritzen', 'Bogholder / blæksprut'],
        ] as [$name, $role]) {
            $nameParts = collect(preg_split('/\s+/', $name))->filter()->values();
            $initials = mb_strtoupper(mb_substr($nameParts->first(), 0, 1).mb_substr($nameParts->last(), 0, 1));
            DB::table('employees')->updateOrInsert(['display_name' => $name], ['user_id' => $name === 'Rasmus Havn Mouritzen' ? $admin?->id : null, 'initials' => $initials, 'job_title' => $role, 'role' => $role, 'status' => 'ACTIVE', 'active' => true, 'booking_capacity' => in_array($role, ['Synsinspektør', 'Teknisk ansvarlig / Ejer'], true), 'updated_at' => now(), 'created_at' => now()]);
        }

        if (Schema::hasTable('departments') && Schema::hasTable('employee_departments')) {
            $ikast = DB::table('departments')->where('name', 'Ikast')->value('id');
            if ($ikast) {
                DB::table('employees')->get()->each(fn ($employee) => DB::table('employee_departments')->updateOrInsert(['employee_id' => $employee->id, 'department_id' => $ikast], ['is_primary' => true, 'created_at' => now(), 'updated_at' => now()]));
            }
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
                if (!$opening) continue;
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
