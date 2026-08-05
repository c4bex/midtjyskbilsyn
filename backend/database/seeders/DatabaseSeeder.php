<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::firstOrCreate(['email' => 'test@example.com'], [
            'name' => 'Test User',
            'password' => bcrypt('password'),
        ]);

        foreach ([
            ['Peter Hartz Jensen', 'Synsinspektør'],
            ['Rasmus Havn Mouritzen', 'Teknisk ansvarlig / Ejer'],
            ['Pernille Havn Mouritzen', 'Bogholder / blæksprut'],
        ] as [$name, $role]) {
            DB::table('employees')->updateOrInsert(['display_name' => $name], ['role' => $role, 'active' => true, 'updated_at' => now(), 'created_at' => now()]);
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
        DB::table('bookings')->updateOrInsert(
            ['source_reference' => 'demo-booking-0800'],
            ['customer_id' => $businessId, 'vehicle_id' => $businessVehicle, 'starts_at' => '2026-08-04 08:00:00', 'ends_at' => '2026-08-04 08:20:00', 'inspection_type' => 'Periodisk syn', 'status' => 'completed', 'source' => 'demo', 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
