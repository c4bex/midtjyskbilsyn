<?php

namespace Database\Seeders;

use App\Services\CapacityPlannerV2;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LegacyBusinessCustomerTestSeeder extends Seeder
{
    private const TEST_BOOKING_PREFIX = 'legacy-test-booking:';

    private const BOOKING_TARGET = 70;

    /**
     * Kunder fra PDF-udtrækket "Kunder fra gammelt system".
     *
     * Listen er bevidst komplet, så filtreringen af U/B-poster kan kontrolleres
     * og genbruges. Kun poster uden U/B bliver skrevet til databasen.
     *
     * @var array<int, array{account: int, name: string}>
     */
    private const LEGACY_CUSTOMERS = [
        ['account' => 329, 'name' => 'A-P Auto U/B'],
        ['account' => 328, 'name' => 'A-P Auto'],
        ['account' => 326, 'name' => 'ABC Ikast'],
        ['account' => 327, 'name' => 'ABC Ikast U/B'],
        ['account' => 846, 'name' => 'AH Motorcykler'],
        ['account' => 918, 'name' => 'AS Facader'],
        ['account' => 330, 'name' => 'Autogården'],
        ['account' => 331, 'name' => 'Autogården U/B'],
        ['account' => 332, 'name' => 'Autohuset'],
        ['account' => 333, 'name' => 'Autohuset U/B'],
        ['account' => 897, 'name' => 'Bakkens Biler'],
        ['account' => 335, 'name' => 'Bbc Biler U/B'],
        ['account' => 334, 'name' => 'Bbc Biler'],
        ['account' => 336, 'name' => 'Bmc Leasing'],
        ['account' => 338, 'name' => 'Bording Biler'],
        ['account' => 339, 'name' => 'Bording Biler U/B'],
        ['account' => 347, 'name' => 'Div U/B'],
        ['account' => 591, 'name' => 'Div venner af huset'],
        ['account' => 349, 'name' => 'DJ Motorcykler U/B'],
        ['account' => 348, 'name' => 'DJ Motorcykler'],
        ['account' => 579, 'name' => 'Ejstrupholm Taxi'],
        ['account' => 674, 'name' => 'Fink Byg'],
        ['account' => 352, 'name' => 'Finn Nielsen Biler'],
        ['account' => 353, 'name' => 'Finn Nielsen Biler U/B'],
        ['account' => 915, 'name' => 'Fonden Bo-Selv'],
        ['account' => 926, 'name' => 'Fonden Bo-Selv'],
        ['account' => 354, 'name' => 'H Hvolgaard'],
        ['account' => 355, 'name' => 'H Hvolgaard U/B'],
        ['account' => 646, 'name' => 'Hammerum Autoværksted'],
        ['account' => 647, 'name' => 'Hammerum Autoværksted U/B'],
        ['account' => 356, 'name' => 'Harry Virkelyst'],
        ['account' => 357, 'name' => 'Harry Virkelyst U/B'],
        ['account' => 836, 'name' => 'HMA Auktion'],
        ['account' => 359, 'name' => 'Holmland biler U/B'],
        ['account' => 358, 'name' => 'Holmland biler'],
        ['account' => 362, 'name' => 'IBF'],
        ['account' => 363, 'name' => 'IBF U/B'],
        ['account' => 364, 'name' => 'Ikast Automobiler'],
        ['account' => 365, 'name' => 'Ikast Automobiler U/B'],
        ['account' => 366, 'name' => 'Ikast Camping & Fritid'],
        ['account' => 367, 'name' => 'Ikast Camping & Fritid U/B'],
        ['account' => 368, 'name' => 'Ikast Dæk og Udstødning'],
        ['account' => 369, 'name' => 'Ikast Dæk og Udstødning U/B'],
        ['account' => 612, 'name' => 'Industri Beton'],
        ['account' => 613, 'name' => 'Industri Beton U/B'],
        ['account' => 370, 'name' => 'Intern'],
        ['account' => 851, 'name' => 'Jørgen Jensen'],
        ['account' => 854, 'name' => "Kim's karrosseri"],
        ['account' => 525, 'name' => 'KJ Autoteknik'],
        ['account' => 526, 'name' => 'KJ Autoteknik U/B'],
        ['account' => 377, 'name' => 'Kjærgaard byg'],
        ['account' => 378, 'name' => 'Kjærgaard byg U/B'],
        ['account' => 557, 'name' => 'KK Wind Solutions Service'],
        ['account' => 607, 'name' => 'Knud Knudsen EL'],
        ['account' => 605, 'name' => 'Køreteknisk anlæg'],
        ['account' => 606, 'name' => 'Køreteknisk anlæg U/B'],
        ['account' => 862, 'name' => "Leschly's Auto- og Industrilak ApS"],
        ['account' => 764, 'name' => 'LJ Montage'],
        ['account' => 921, 'name' => 'MC-Huset'],
        ['account' => 381, 'name' => 'MF-Auto'],
        ['account' => 382, 'name' => 'MF-Auto U/B'],
        ['account' => 385, 'name' => 'Midtbyens Auto'],
        ['account' => 386, 'name' => 'Midtbyens Auto U/B'],
        ['account' => 728, 'name' => 'MTEK Autoteknik'],
        ['account' => 729, 'name' => 'MTEK Autoteknik U/B'],
        ['account' => 388, 'name' => 'MUS Automotive ApS'],
        ['account' => 389, 'name' => 'MUS Automotive ApS U/B'],
        ['account' => 859, 'name' => 'Ole Winther Auto'],
        ['account' => 795, 'name' => 'PTest'],
        ['account' => 396, 'name' => 'Ruskær Auto'],
        ['account' => 397, 'name' => 'Ruskær Auto U/B'],
        ['account' => 407, 'name' => 'STS Biler U/B'],
        ['account' => 406, 'name' => 'STS Biler'],
        ['account' => 408, 'name' => 'Super-Dæk'],
        ['account' => 409, 'name' => 'Super-Dæk U/B'],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Denne seeder må kun køres i local eller testing.');
        }

        $customers = array_values(array_filter(
            self::LEGACY_CUSTOMERS,
            fn (array $customer): bool => ! str_contains(mb_strtoupper($customer['name']), 'U/B'),
        ));

        if (count($customers) !== 47) {
            throw new RuntimeException('Kundelisten skal indeholde præcis 47 aktive poster efter U/B-filtrering.');
        }

        $summary = DB::transaction(function () use ($customers): array {
            $now = now();
            $customerIds = [];
            $vehicleIds = [];

            foreach ($customers as $customer) {
                $externalReference = 'legacy-business:'.$customer['account'];
                $existingCustomer = DB::table('customers')
                    ->where('external_reference', $externalReference)
                    ->first();

                if ($existingCustomer) {
                    DB::table('customers')->where('id', $existingCustomer->id)->update([
                        'display_name' => $customer['name'],
                        'customer_type' => 'business',
                        'updated_at' => $now,
                    ]);
                    $customerId = (int) $existingCustomer->id;
                } else {
                    $customerId = (int) DB::table('customers')->insertGetId([
                        'display_name' => $customer['name'],
                        'email' => null,
                        'customer_type' => 'business',
                        'external_reference' => $externalReference,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $customerIds[] = $customerId;

                DB::table('business_portal_settings')->insertOrIgnore([
                    'customer_id' => $customerId,
                    'customer_number' => (string) $customer['account'],
                    'default_department' => 'Ikast',
                    'allowed_departments' => json_encode(['Ikast'], JSON_THROW_ON_ERROR),
                    'allowed_inspection_types' => null,
                    'portal_active' => false,
                    'sms_active' => false,
                    'requisition_requirement' => 'optional',
                    'change_cutoff_minutes' => 120,
                    'booking_horizon_days' => 90,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $registration = 'TST'.str_pad((string) $customer['account'], 5, '0', STR_PAD_LEFT);
                $existingVehicle = DB::table('vehicles')
                    ->where('registration_normalized', $registration)
                    ->first();

                if ($existingVehicle) {
                    if ((int) $existingVehicle->customer_id !== $customerId) {
                        throw new RuntimeException("Testnummerpladen {$registration} bruges allerede af en anden kunde.");
                    }
                    $vehicleId = (int) $existingVehicle->id;
                } else {
                    [$make, $model] = $this->testVehicleFor($customer['account']);
                    $vehicleId = (int) DB::table('vehicles')->insertGetId([
                        'customer_id' => $customerId,
                        'registration_normalized' => $registration,
                        'make' => $make,
                        'model' => $model,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                $vehicleIds[$customerId] = $vehicleId;
            }

            DB::table('bookings')
                ->where('source_reference', 'like', self::TEST_BOOKING_PREFIX.'%')
                ->delete();

            $bookingCount = $this->createBookings($customers, $customerIds, $vehicleIds);

            return [
                'customers' => count($customers),
                'excluded' => count(self::LEGACY_CUSTOMERS) - count($customers),
                'bookings' => $bookingCount,
            ];
        });

        $this->command?->info(
            "Oprettet/opdateret {$summary['customers']} branchekunder, frasorteret {$summary['excluded']} U/B-linjer og oprettet {$summary['bookings']} testbookinger.",
        );
    }

    /**
     * @param  array<int, array{account: int, name: string}>  $customers
     * @param  array<int, int>  $customerIds
     * @param  array<int, int>  $vehicleIds
     */
    private function createBookings(array $customers, array $customerIds, array $vehicleIds): int
    {
        $planner = app(CapacityPlannerV2::class);
        $timezone = config('capacity_planner.timezone');
        $departmentId = (int) DB::table('departments')->where('name', 'Ikast')->value('id');

        if ($departmentId <= 0) {
            throw new RuntimeException('Afdelingen Ikast findes ikke.');
        }

        $dates = collect(range(1, 14))
            ->map(fn (int $offset): CarbonImmutable => CarbonImmutable::today($timezone)->addDays($offset))
            ->filter(function (CarbonImmutable $date) use ($planner): bool {
                return count($planner->buildDayPlan('ikast', $date->toDateString(), 'Periodisk syn')['publicAvailableSlots']) > 0;
            })
            ->values();

        if ($dates->isEmpty()) {
            throw new RuntimeException('Der blev ikke fundet ledig kapacitet i de næste 14 dage.');
        }

        $inspectionTypes = ['Periodisk syn', 'Omsyn', 'Varebilssyn', 'Periodisk syn', 'Motorcykelsyn'];
        $created = 0;

        foreach ($dates as $dateIndex => $date) {
            $remainingDates = $dates->count() - $dateIndex;
            $dailyTarget = (int) ceil((self::BOOKING_TARGET - $created) / max(1, $remainingDates));
            $createdToday = 0;

            while ($created < self::BOOKING_TARGET && $createdToday < $dailyTarget) {
                $inspectionType = $created > 0 && $created % 13 === 0
                    ? 'Toldsyn'
                    : $inspectionTypes[$created % count($inspectionTypes)];
                $plan = $planner->buildDayPlan('ikast', $date->toDateString(), $inspectionType);
                $availableSlots = array_values($plan['publicAvailableSlots']);

                if ($availableSlots === [] && $inspectionType === 'Toldsyn') {
                    $inspectionType = 'Periodisk syn';
                    $plan = $planner->buildDayPlan('ikast', $date->toDateString(), $inspectionType);
                    $availableSlots = array_values($plan['publicAvailableSlots']);
                }

                if ($availableSlots === []) {
                    break;
                }

                $spreadIndex = min(
                    count($availableSlots) - 1,
                    (int) floor((($createdToday + 1) * count($availableSlots)) / ($dailyTarget + 1)),
                );
                $time = $availableSlots[$spreadIndex];
                $startsAt = CarbonImmutable::createFromFormat(
                    'Y-m-d H:i',
                    $date->toDateString().' '.$time,
                    $timezone,
                );
                $slotCount = max(1, (int) ($plan['requiredSlots'] ?? 1));
                $customerIndex = $created % count($customers);
                $customer = $customers[$customerIndex];
                $customerId = $customerIds[$customerIndex];

                DB::table('bookings')->insert([
                    'department_id' => $departmentId,
                    'customer_id' => $customerId,
                    'business_customer_id' => $customerId,
                    'business_portal_user_id' => null,
                    'vehicle_id' => $vehicleIds[$customerId],
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->addMinutes($slotCount * CapacityPlannerV2::INTERVAL),
                    'slot_count' => $slotCount,
                    'inspection_type' => $inspectionType,
                    'requisition_number' => $created % 4 === 0 ? 'TEST-'.str_pad((string) ($created + 1), 4, '0', STR_PAD_LEFT) : null,
                    'status' => 'confirmed',
                    'source' => 'test_data',
                    'booking_channel' => 'test_seed',
                    'source_reference' => self::TEST_BOOKING_PREFIX.str_pad((string) ($created + 1), 3, '0', STR_PAD_LEFT),
                    'contact_name' => $customer['name'],
                    'customer_note' => 'Automatisk oprettet testbooking',
                    'internal_note' => 'Testdata fra gammel kundeliste',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $created++;
                $createdToday++;
            }
        }

        if ($created !== self::BOOKING_TARGET) {
            throw new RuntimeException("Der var kun kapacitet til {$created} af ".self::BOOKING_TARGET.' testbookinger.');
        }

        return $created;
    }

    /** @return array{0: string, 1: string} */
    private function testVehicleFor(int $account): array
    {
        $vehicles = [
            ['Volkswagen', 'Golf'],
            ['Ford', 'Transit'],
            ['Toyota', 'Proace'],
            ['Mercedes-Benz', 'Sprinter'],
            ['Skoda', 'Octavia'],
            ['Peugeot', 'Partner'],
            ['Renault', 'Master'],
            ['Citroën', 'Berlingo'],
        ];

        return $vehicles[$account % count($vehicles)];
    }
}
