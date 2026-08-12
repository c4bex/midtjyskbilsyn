<?php

namespace Tests\Feature;

use App\Http\Middleware\Permission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OperationsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $employeeId = DB::table('employees')->insertGetId(['user_id' => $this->user->id, 'display_name' => 'Testadministrator', 'role' => 'Teknisk ansvarlig / Ejer', 'active' => true, 'booking_capacity' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(1, 5) as $weekday) {
            DB::table('availability_rules')->insert(['kind' => 'opening_hours', 'weekday' => $weekday, 'starts_at' => '08:00', 'ends_at' => '16:00', 'label' => 'Normal åbningstid', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach (range(1, 5) as $weekday) {
            DB::table('employee_work_rules')->insert(['employee_id' => $employeeId, 'weekday' => $weekday, 'starts_at' => '08:00', 'ends_at' => '16:00', 'working' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/bookings?date=2026-08-04')->assertUnauthorized();
    }

    public function test_notification_center_uses_real_events_and_remembers_state_per_user(): void
    {
        DB::table('sms_messages')->insert([
            'kind' => 'reminder', 'recipient_masked' => '+45 ··· 56', 'status' => 'failed',
            'idempotency_key' => 'notification-test-sms', 'failed_at' => now(),
            'error_message' => 'Udbyderen afviste beskeden', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/notifications')
            ->assertOk()->assertJsonPath('unreadCount', 1)
            ->assertJsonPath('notifications.0.title', 'SMS kunne ikke sendes')
            ->assertJsonPath('notifications.0.actionView', 'sms');
        $notificationId = $response->json('notifications.0.id');

        $this->actingAs($this->user)->patchJson('/api/notifications/'.$notificationId, ['action' => 'read'])->assertOk();
        $this->actingAs($this->user)->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('unreadCount', 0)->assertJsonPath('notifications.0.read', true);

        $otherUser = User::factory()->create();
        DB::table('employees')->insert([
            'user_id' => $otherUser->id, 'display_name' => 'Anden administrator',
            'role' => 'Teknisk ansvarlig / Ejer', 'active' => true,
            'booking_capacity' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($otherUser)->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('unreadCount', 1)->assertJsonPath('notifications.0.read', false);

        $this->actingAs($this->user)->patchJson('/api/notifications/'.$notificationId, ['action' => 'dismiss'])->assertOk();
        $this->actingAs($this->user)->getJson('/api/notifications')->assertOk()
            ->assertJsonCount(0, 'notifications')->assertJsonPath('unreadCount', 0);
        $this->actingAs($otherUser)->getJson('/api/notifications')->assertOk()->assertJsonCount(1, 'notifications');
    }

    public function test_private_booking_is_saved_with_automatic_sms_plan(): void
    {
        $date = now()->next(CarbonImmutable::MONDAY)->toDateString();
        $response = $this->actingAs($this->user)->postJson('/api/bookings', [
            'customer' => 'Fiktiv Privatkunde', 'customerType' => 'private', 'plate' => 'AB12345',
            'vehicle' => 'Volkswagen Golf', 'date' => $date, 'time' => '08:00',
            'inspection' => 'Periodisk syn', 'status' => 'confirmed',
        ])->assertCreated();

        $bookingId = $response->json('booking.id');
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => 'confirmed']);
        $this->assertDatabaseHas('sms_messages', ['booking_id' => $bookingId, 'kind' => 'confirmation', 'status' => 'held']);
        $this->assertDatabaseHas('sms_messages', ['booking_id' => $bookingId, 'kind' => 'reminder', 'status' => 'held']);
    }

    public function test_public_customer_can_find_change_and_cancel_booking_with_secure_link(): void
    {
        $date = now()->next(CarbonImmutable::MONDAY)->toDateString();
        $created = $this->withHeader('x-public-origin', 'http://localhost:4317')->postJson('/api/public/bookings', [
            'customer' => 'Selvbetjeningskunde', 'phone' => '20 12 34 56', 'email' => 'kunde@example.test',
            'plate' => 'SB12345', 'date' => $date, 'time' => '08:00', 'inspection' => 'Periodisk syn',
        ])->assertCreated();

        $manageUrl = $created->json('booking.manageUrl');
        $this->assertStringStartsWith('/booking/manage?token=', $manageUrl);
        $token = urldecode((string) parse_url($manageUrl, PHP_URL_QUERY));
        $token = str_replace('token=', '', $token);
        $this->assertDatabaseHas('booking_management_tokens', ['token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('booking_management_tokens', ['token_hash' => $token]);
        $this->getJson('/api/public/bookings/manage?token='.urlencode($token))
            ->assertOk()->assertJsonPath('booking.plate', 'SB 12 345')->assertJsonPath('booking.canChange', true);
        $this->postJson('/api/public/bookings/lookup', ['plate' => 'SB 12 345', 'phone' => '+45 20 12 34 56'])
            ->assertOk()->assertJsonStructure(['manageUrl']);

        $this->patchJson('/api/public/bookings/manage', [
            'token' => $token, 'action' => 'reschedule', 'date' => $date, 'time' => '08:20',
        ])->assertOk()->assertJsonPath('booking.time', '08:20');
        $bookingId = (int) $created->json('booking.id');
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => 'confirmed']);
        $this->assertDatabaseHas('sms_messages', ['booking_id' => $bookingId, 'kind' => 'changed']);

        $this->patchJson('/api/public/bookings/manage', ['token' => $token, 'action' => 'cancel'])
            ->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('sms_messages', ['booking_id' => $bookingId, 'kind' => 'cancelled']);
    }

    public function test_public_booking_endpoints_reject_invalid_types_dates_and_phone_numbers(): void
    {
        $typeId = DB::table('inspection_types')->where('name', 'Periodisk syn')->value('id');
        $past = now()->subDay()->toDateString();
        $outsideHorizon = now()->addDays(61)->toDateString();

        $this->getJson("/api/public/availability?locationId=ikast&bookingTypeId={$typeId}&from={$past}&to={$past}")
            ->assertUnprocessable();
        $this->getJson("/api/public/availability?locationId=ikast&bookingTypeId={$typeId}&from={$outsideHorizon}&to={$outsideHorizon}")
            ->assertUnprocessable();
        $this->postJson('/api/public/bookings', [
            'customer' => 'Ugyldig booking', 'phone' => '123', 'plate' => 'AB12345',
            'date' => now()->next(CarbonImmutable::MONDAY)->toDateString(), 'time' => '08:00', 'inspection' => 'Findes ikke',
        ])->assertUnprocessable();
    }

    public function test_global_search_finds_booking_by_plate_and_requisition_number(): void
    {
        $nearDate = now()->next(CarbonImmutable::MONDAY)->toDateString();
        $laterDate = now()->next(CarbonImmutable::MONDAY)->addWeek()->toDateString();
        $bookingPayload = [
            'customer' => 'Søgekunde ApS', 'customerType' => 'business', 'plate' => 'EN48111', 'vehicle' => 'Ford Transit',
            'requisitionNumber' => 'REKV-2026-42', 'time' => '08:00', 'inspection' => 'Periodisk syn',
        ];
        $this->actingAs($this->user)->postJson('/api/bookings', $bookingPayload + ['date' => $laterDate])->assertCreated();
        $bookingId = $this->actingAs($this->user)->postJson('/api/bookings', $bookingPayload + ['date' => $nearDate])->assertCreated()->json('booking.id');

        $this->actingAs($this->user)->getJson('/api/search?q=EN48111')->assertOk()->assertJsonPath('results.0.type', 'booking')->assertJsonPath('results.0.booking.id', (string) $bookingId)->assertJsonPath('results.0.booking.date', $nearDate);
        $this->actingAs($this->user)->getJson('/api/search?q=REKV-2026-42')->assertOk()->assertJsonPath('results.0.booking.requisitionNumber', 'REKV-2026-42');
    }

    public function test_employee_permissions_can_restrict_booking_writes(): void
    {
        DB::table('employees')->where('user_id', $this->user->id)->update(['role' => 'Begrænset adgang']);
        $now = now();
        foreach (array_keys(Permission::catalog()) as $key) {
            DB::table('employee_permissions')->insert(['employee_id' => DB::table('employees')->where('user_id', $this->user->id)->value('id'), 'permission_key' => $key, 'allowed' => $key !== 'bookings.write', 'created_at' => $now, 'updated_at' => $now]);
        }

        $this->actingAs($this->user)->postJson('/api/bookings', [
            'customer' => 'Begrænset bruger', 'customerType' => 'private', 'plate' => 'ZZ12345', 'vehicle' => 'Volkswagen Golf',
            'date' => now()->next(CarbonImmutable::MONDAY)->toDateString(), 'time' => '08:00', 'inspection' => 'Periodisk syn',
        ])->assertForbidden();
    }

    public function test_read_permissions_are_enforced_by_the_api(): void
    {
        $employeeId = DB::table('employees')->where('user_id', $this->user->id)->value('id');
        DB::table('employees')->where('id', $employeeId)->update(['role' => 'Begrænset adgang']);
        $now = now();
        foreach (array_keys(Permission::catalog()) as $key) {
            DB::table('employee_permissions')->insert([
                'employee_id' => $employeeId,
                'permission_key' => $key,
                'allowed' => $key === 'customers.read',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->actingAs($this->user)->getJson('/api/customers')->assertOk();
        $this->actingAs($this->user)->getJson('/api/bookings')->assertForbidden();
        $this->actingAs($this->user)->getJson('/api/calendar/week')->assertForbidden();
        $this->actingAs($this->user)->getJson('/api/health')->assertForbidden();
        $this->actingAs($this->user)->getJson('/api/sms/messages')->assertForbidden();
    }

    public function test_production_seeding_is_idempotent_and_never_resets_existing_passwords(): void
    {
        config()->set('app.seed_admin_email', 'drift@example.test');
        config()->set('app.seed_admin_password', 'startkode');
        config()->set('app.seed_admin_name', 'Drift Admin');
        config()->set('app.seed_demo_data', false);

        $this->seed();
        $admin = User::where('email', 'drift@example.test')->firstOrFail();
        $admin->forceFill(['password' => Hash::make('bevar-denne-kode')])->save();
        DB::table('availability_rules')->where('kind', 'opening_hours')->where('weekday', 1)->update(['ends_at' => '16:45']);

        $this->seed();

        $this->assertTrue(Hash::check('bevar-denne-kode', $admin->fresh()->password));
        $this->assertSame('16:45', substr((string) DB::table('availability_rules')->where('kind', 'opening_hours')->where('weekday', 1)->value('ends_at'), 0, 5));
        $this->assertDatabaseMissing('customers', ['external_reference' => 'demo-private-1']);
    }

    public function test_untrusted_origin_cannot_be_used_in_booking_management_messages(): void
    {
        config()->set('app.frontend_url', 'https://booking.midtjyskbilsyn.dk');
        config()->set('app.trusted_frontend_hosts', []);
        $date = now()->next(CarbonImmutable::MONDAY)->toDateString();

        $created = $this->withHeader('x-public-origin', 'https://falsk.trycloudflare.com')->postJson('/api/public/bookings', [
            'customer' => 'Sikker kunde', 'phone' => '20 12 34 56', 'plate' => 'SK12345',
            'date' => $date, 'time' => '08:00', 'inspection' => 'Periodisk syn',
        ])->assertCreated();

        $bookingId = (int) $created->json('booking.id');
        $message = (string) DB::table('sms_messages')->where('booking_id', $bookingId)->where('kind', 'confirmation')->value('body');
        $this->assertStringContainsString('https://booking.midtjyskbilsyn.dk/booking/manage', $message);
        $this->assertStringNotContainsString('falsk.trycloudflare.com', $message);
    }

    public function test_employee_actions_enforce_their_granular_permissions(): void
    {
        $employeeId = DB::table('employees')->where('user_id', $this->user->id)->value('id');
        DB::table('employees')->where('id', $employeeId)->update(['role' => 'Begrænset adgang']);
        $now = now();
        foreach (array_keys(Permission::catalog()) as $key) {
            DB::table('employee_permissions')->insert([
                'employee_id' => $employeeId,
                'permission_key' => $key,
                'allowed' => $key === 'employees.write',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->actingAs($this->user)->postJson('/api/employees', [
            'type' => 'work_rule', 'employeeId' => $employeeId, 'weekday' => 1,
            'startsAt' => '08:00', 'endsAt' => '16:00', 'working' => true,
        ])->assertForbidden();
        $this->actingAs($this->user)->postJson('/api/employees', [
            'type' => 'absence', 'employeeId' => $employeeId, 'kind' => 'Ferie',
            'dateFrom' => now()->addWeek()->toDateString(), 'dateTo' => now()->addWeek()->toDateString(),
        ])->assertForbidden();
        $this->actingAs($this->user)->postJson('/api/employees', [
            'type' => 'employee_permissions', 'employeeId' => $employeeId, 'permissions' => [],
        ])->assertForbidden();
    }

    public function test_calendar_returns_available_slots_from_mysql_rules(): void
    {
        $monday = now()->next('Monday')->toDateString();
        $this->actingAs($this->user)->getJson('/api/calendar/week?start='.$monday)
            ->assertOk()->assertJsonPath('days.0.closed', false)->assertJsonPath('days.0.availableSlots.0', '08:00');
    }

    public function test_active_booking_slot_cannot_be_booked_twice(): void
    {
        $payload = [
            'customer' => 'Fiktiv Kunde', 'customerType' => 'business', 'plate' => 'XY12345',
            'vehicle' => 'Ford Transit', 'date' => now()->addDays(7)->toDateString(), 'time' => '09:20',
            'inspection' => 'Periodisk syn', 'status' => 'confirmed',
        ];

        $this->actingAs($this->user)->postJson('/api/bookings', $payload)->assertCreated();
        $this->actingAs($this->user)->postJson('/api/bookings', $payload)->assertConflict();
        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_two_inspectors_allow_two_bookings_at_the_same_time(): void
    {
        $date = now()->next('Monday')->toDateString();
        $secondEmployee = DB::table('employees')->insertGetId(['display_name' => 'Ekstra synsinspektør', 'role' => 'Synsinspektør', 'active' => true, 'booking_capacity' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('employee_work_rules')->insert(['employee_id' => $secondEmployee, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '16:00', 'working' => true, 'created_at' => now(), 'updated_at' => now()]);
        $ikast = DB::table('departments')->where('name', 'Ikast')->value('id');
        DB::table('employee_departments')->insert(['employee_id' => $secondEmployee, 'department_id' => $ikast, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        $payload = ['customer' => 'Fiktiv Kunde', 'customerType' => 'business', 'vehicle' => 'Ford Transit', 'date' => $date, 'time' => '09:20', 'inspection' => 'Periodisk syn', 'status' => 'confirmed'];

        $this->actingAs($this->user)->postJson('/api/bookings', $payload + ['plate' => 'XY12345'])->assertCreated();
        $calendar = $this->actingAs($this->user)->getJson('/api/calendar/week?start='.$date)->assertOk()->assertJsonPath('days.0.staffedInspectors', 2);
        $this->assertNotContains('09:20', $calendar->json('days.0.availableSlots'), 'Kun én offentlig bookingstart pr. blok udstilles i V2');
        $this->actingAs($this->user)->postJson('/api/bookings', $payload + ['plate' => 'XY12346'])->assertCreated();
        $this->actingAs($this->user)->postJson('/api/bookings', $payload + ['plate' => 'XY12347'])->assertConflict();

        $final = $this->actingAs($this->user)->getJson('/api/calendar/week?start='.$date)->assertOk();
        $this->assertNotContains('09:20', $final->json('days.0.availableSlots'));
    }

    public function test_employee_rotation_changes_capacity_without_daily_configuration(): void
    {
        $date = '2026-08-10'; // En uge efter ankermandagen: uge 2 i et to-ugers rul.
        $employeeId = DB::table('employees')->where('booking_capacity', true)->value('id');
        DB::table('employee_work_rules')->where('employee_id', $employeeId)->where('weekday', 1)->update(['cycle_weeks' => 2, 'cycle_week' => 1]);

        $this->actingAs($this->user)->getJson('/api/calendar/week?start='.$date)->assertOk()->assertJsonPath('days.0.staffedInspectors', 0);

        DB::table('employee_work_rules')->where('employee_id', $employeeId)->where('weekday', 1)->update(['cycle_week' => 2]);
        $this->actingAs($this->user)->getJson('/api/calendar/week?start='.$date)->assertOk()->assertJsonPath('days.0.staffedInspectors', 1);
    }

    public function test_configured_break_is_removed_from_every_availability_contract(): void
    {
        $date = now()->next(CarbonImmutable::MONDAY)->toDateString();
        DB::table('availability_rules')->insert([
            'kind' => 'break', 'weekday' => 1, 'starts_at' => '12:20', 'ends_at' => '13:00',
            'label' => 'Frokostpause', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $calendar = $this->actingAs($this->user)->getJson('/api/calendar/week?start='.$date)->assertOk();
        $this->assertNotContains('12:20', $calendar->json('days.0.availableSlots'));
        $this->assertNotContains('12:40', $calendar->json('days.0.availableSlots'));
        $calendar->assertJsonPath('days.0.timeSlots', 24)->assertJsonPath('days.0.concurrentCapacity', 1);

        $public = $this->getJson('/api/public/availability?locationId=ikast&bookingTypeId=1&from='.$date.'&to='.$date)->assertOk();
        $this->assertNotContains('12:20', $public->json('days.0.availableSlots'));
        $this->assertNotContains('12:40', $public->json('days.0.availableSlots'));
        // Pausen lukker sine to tider, men må ikke genstarte buffercyklussen.
        $public->assertJsonPath('days.0.availableTimeSlots', 16)->assertJsonPath('days.0.availablePlaces', 16);
    }

    public function test_multiple_breaks_can_be_saved_on_the_same_weekday(): void
    {
        $date = now()->next(CarbonImmutable::MONDAY)->toDateString();
        $this->actingAs($this->user)->patchJson('/api/availability', [
            'weekday' => 1, 'closed' => false, 'startsAt' => '08:00', 'endsAt' => '16:00',
            'breaks' => [
                ['startsAt' => '09:00', 'endsAt' => '09:20'],
                ['startsAt' => '12:00', 'endsAt' => '12:40'],
            ],
        ])->assertOk();

        $this->assertDatabaseCount('availability_rules', 7);
        $this->assertSame(2, DB::table('availability_rules')->where('weekday', 1)->where('kind', 'break')->count());
        $availability = $this->actingAs($this->user)->getJson('/api/bookings?date='.$date)->assertOk();
        $this->assertNotContains('09:00', $availability->json('availableSlots'));
        $this->assertNotContains('12:00', $availability->json('availableSlots'));
        $this->assertNotContains('12:20', $availability->json('availableSlots'));
    }

    public function test_danish_holiday_suggestions_include_movable_dates_and_existing_closures(): void
    {
        DB::table('availability_rules')->insert([
            'kind' => 'holiday', 'date_from' => '2027-05-06', 'date_to' => '2027-05-06',
            'label' => 'Kristi himmelfartsdag', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->getJson('/api/availability/holiday-suggestions?year=2027')->assertOk();

        $response->assertJsonCount(10, 'holidays')
            ->assertJsonFragment(['name' => 'Skærtorsdag', 'date' => '2027-03-25'])
            ->assertJsonFragment(['name' => '2. påskedag', 'date' => '2027-03-29'])
            ->assertJsonFragment(['name' => 'Kristi himmelfartsdag', 'date' => '2027-05-06', 'alreadyClosed' => true, 'closedBy' => 'Kristi himmelfartsdag'])
            ->assertJsonFragment(['name' => 'Pinsedag', 'date' => '2027-05-16', 'alreadyClosed' => true, 'closedBy' => 'Fast lukkedag (søndag)']);
    }

    public function test_holiday_suggestions_can_be_applied_without_duplicates_or_weekend_closures(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 10:00:00');
        try {
            $this->actingAs($this->user)->postJson('/api/availability/holiday-suggestions/apply', [
                'year' => 2027,
                'dates' => ['2027-01-01', '2027-12-25'],
            ])->assertOk()->assertJsonPath('createdCount', 1)->assertJsonPath('skippedCount', 1);

            $this->assertDatabaseHas('availability_rules', ['kind' => 'holiday', 'date_from' => '2027-01-01', 'date_to' => '2027-01-01', 'label' => 'Nytårsdag']);
            $this->assertDatabaseMissing('availability_rules', ['kind' => 'holiday', 'date_from' => '2027-12-25']);

            $this->actingAs($this->user)->postJson('/api/availability/holiday-suggestions/apply', [
                'year' => 2027,
                'dates' => ['2027-01-01'],
            ])->assertOk()->assertJsonPath('createdCount', 0)->assertJsonPath('skippedCount', 1);
            $this->assertSame(1, DB::table('availability_rules')->where('kind', 'holiday')->whereDate('date_from', '2027-01-01')->count());

            $this->actingAs($this->user)->postJson('/api/availability/holiday-suggestions/apply', [
                'year' => 2027,
                'dates' => ['2027-02-01'],
            ])->assertUnprocessable()->assertJsonPath('error', 'Listen indeholder en dato, som ikke er en dansk helligdag i det valgte år');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_employee_endpoint_returns_authoritative_capacity_and_permissions(): void
    {
        CarbonImmutable::setTestNow('2026-08-12 10:00:00');
        try {
            $response = $this->actingAs($this->user)->getJson('/api/employees')->assertOk();
            $response->assertJsonPath('capacitySummary.today.concurrentCapacity', 1)
                ->assertJsonPath('employees.0.permissions.0', 'bookings.read');
            $this->assertNotEmpty($response->json('permissionCatalog'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_toldsyn_reserves_two_adjacent_booking_slots_as_one_booking(): void
    {
        $date = now()->next('Monday')->toDateString();
        $response = $this->actingAs($this->user)->postJson('/api/bookings', [
            'customer' => 'Fiktiv Toldsynskunde', 'customerType' => 'business', 'plate' => 'TL12345',
            'vehicle' => 'Mercedes Sprinter', 'date' => $date, 'time' => '10:00', 'inspection' => 'Toldsyn',
        ])->assertCreated();

        $bookingId = $response->json('booking.id');
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'slot_count' => 2, 'inspection_type' => 'Toldsyn']);
        $this->assertSame('10:40:00', CarbonImmutable::parse(DB::table('bookings')->where('id', $bookingId)->value('ends_at'))->format('H:i:s'));

        $available = $this->actingAs($this->user)->getJson('/api/bookings?date='.$date.'&inspection=Toldsyn')
            ->assertOk()->json('availableSlots');
        $this->assertNotContains('10:00', $available);
        $this->actingAs($this->user)->postJson('/api/bookings', [
            'customer' => 'Anden kunde', 'customerType' => 'business', 'plate' => 'TL12346', 'vehicle' => 'Ford Transit',
            'date' => $date, 'time' => '10:20', 'inspection' => 'Toldsyn',
        ])->assertConflict();
    }

    public function test_planning_returns_profiles_and_buffer_conflicts_without_moving_bookings(): void
    {
        $date = now()->next('Monday')->toDateString();
        $this->actingAs($this->user)->getJson('/api/planning?date='.$date)->assertOk()
            ->assertJsonFragment(['name' => 'Én medarbejder'])->assertJsonFragment(['name' => 'Toldsyn', 'required_slots' => 2]);

        $bookingId = $this->actingAs($this->user)->postJson('/api/bookings', [
            'customer' => 'Konfliktkunde', 'customerType' => 'business', 'plate' => 'BF12345', 'vehicle' => 'Ford Focus',
            'date' => $date, 'time' => '11:00', 'inspection' => 'Periodisk syn',
        ])->assertCreated()->json('booking.id');
        $this->actingAs($this->user)->postJson('/api/planning/buffers', [
            'date' => $date, 'startsAt' => '11:00', 'endsAt' => '11:20', 'reason' => 'Ekstra kontrol',
        ])->assertCreated()->assertJsonPath('conflicts.0', (string) $bookingId);
        $this->assertDatabaseHas('vehicles', ['registration_normalized' => 'BF12345']);
    }

    public function test_invoice_control_requires_reason_for_edits_and_locks_after_approval(): void
    {
        $invoiceId = DB::table('invoice_drafts')->insertGetId(['customer_name' => 'Test Erhverv ApS', 'period' => 'August 2026', 'description' => 'Syn', 'quantity' => 1, 'unit_price_ore' => 40000, 'status' => 'Klargøres', 'payment_terms' => 'netto_14', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('invoice_lines')->insert(['invoice_draft_id' => $invoiceId, 'source_system' => 'test', 'source_key' => 'test:invoice:'.$invoiceId, 'description' => 'Syn · AB12345', 'quantity' => 1, 'unit' => 'stk.', 'unit_price_ore' => 40000, 'original_description' => 'Syn · AB12345', 'original_unit_price_ore' => 40000, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->user)->patchJson('/api/invoices', ['id' => $invoiceId, 'description' => 'Rettet syn', 'quantity' => 1, 'unitPriceOre' => 42000, 'status' => 'Klargøres'])->assertStatus(422);
        $this->actingAs($this->user)->patchJson('/api/invoices', ['id' => $invoiceId, 'description' => 'Rettet syn', 'quantity' => 1, 'unitPriceOre' => 42000, 'status' => 'Klargøres', 'reason' => 'Aftalt ny kundepris'])->assertOk();
        $this->actingAs($this->user)->postJson('/api/invoices/approve', ['ids' => [$invoiceId]])->assertOk();
        $this->assertDatabaseHas('invoice_drafts', ['id' => $invoiceId, 'status' => 'APPROVED']);
        $this->assertDatabaseHas('invoice_revisions', ['invoice_draft_id' => $invoiceId, 'field' => 'unit_price_ore']);
    }

    public function test_invoice_approval_requires_approval_permission_and_payment_terms(): void
    {
        $employeeId = DB::table('employees')->where('user_id', $this->user->id)->value('id');
        DB::table('employees')->where('id', $employeeId)->update(['role' => 'Bogholder / blæksprut']);
        $invoiceId = DB::table('invoice_drafts')->insertGetId(['customer_name' => 'Kontrolkunde ApS', 'period' => 'August 2026', 'description' => 'Syn', 'quantity' => 1, 'unit_price_ore' => 40000, 'status' => 'Klargøres', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('invoice_lines')->insert(['invoice_draft_id' => $invoiceId, 'source_system' => 'test', 'source_key' => 'approval:'.$invoiceId, 'description' => 'Syn', 'quantity' => 1, 'unit' => 'stk.', 'unit_price_ore' => 40000, 'original_description' => 'Syn', 'original_unit_price_ore' => 40000, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->user)->postJson('/api/invoices/approve', ['ids' => [$invoiceId]])->assertForbidden();
        DB::table('employees')->where('id', $employeeId)->update(['role' => 'Teknisk ansvarlig / Ejer']);
        $this->actingAs($this->user)->postJson('/api/invoices/approve', ['ids' => [$invoiceId]])
            ->assertUnprocessable()->assertJsonPath('blocked.'.$invoiceId.'.0', 'Betalingsbetingelser skal angives før godkendelse');
    }

    public function test_business_customer_billing_profile_is_saved_without_external_integration(): void
    {
        $customerId = DB::table('customers')->insertGetId(['display_name' => 'Test Erhverv ApS', 'customer_type' => 'business', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->user)->patchJson('/api/customers/'.$customerId.'/billing', ['cvrNumber' => '12345678', 'invoiceEmail' => 'faktura@test.dk', 'billingMethod' => 'email', 'paymentTerms' => 'netto_14', 'requiresRequisition' => true])->assertOk()->assertJsonPath('billing.cvr_number', '12345678');
        $this->assertDatabaseHas('customer_billing_profiles', ['customer_id' => $customerId, 'billing_method' => 'email', 'requires_requisition' => true]);
    }

    public function test_business_portal_roles_share_bookings_but_only_admin_receives_invoice_data(): void
    {
        $customerId = DB::table('customers')->insertGetId(['display_name' => 'Portal Kunde ApS', 'customer_type' => 'business', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('business_portal_settings')->insert(['customer_id' => $customerId, 'portal_active' => true, 'sms_active' => true, 'requisition_requirement' => 'optional', 'change_cutoff_minutes' => 120, 'booking_horizon_days' => 90, 'created_at' => now(), 'updated_at' => now()]);
        $adminId = DB::table('business_portal_users')->insertGetId(['customer_id' => $customerId, 'name' => 'Portal Admin', 'email' => 'portal-admin@example.test', 'phone' => '20123456', 'password' => Hash::make('password123'), 'role' => 'admin', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $employeeId = DB::table('business_portal_users')->insertGetId(['customer_id' => $customerId, 'name' => 'Portal Medarbejder', 'email' => 'portal-medarbejder@example.test', 'password' => Hash::make('password123'), 'role' => 'employee', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $vehicleId = DB::table('vehicles')->insertGetId(['customer_id' => $customerId, 'registration_normalized' => 'PO12345', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bookings')->insert(['customer_id' => $customerId, 'business_customer_id' => $customerId, 'business_portal_user_id' => $adminId, 'vehicle_id' => $vehicleId, 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addMinutes(20), 'inspection_type' => 'Periodisk syn', 'status' => 'confirmed', 'source' => 'business_portal', 'booking_channel' => 'business_portal', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('customer_billing_profiles')->insert(['customer_id' => $customerId, 'invoice_email' => 'faktura@portal.test', 'billing_method' => 'email', 'payment_terms' => 'netto_14', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('invoice_drafts')->insert(['customer_name' => 'Portal Kunde ApS', 'period' => 'August 2026', 'description' => 'Syn', 'quantity' => 1, 'unit_price_ore' => 40000, 'status' => 'Klargøres', 'created_at' => now(), 'updated_at' => now()]);

        $this->withSession(['business_portal_user_id' => $employeeId])->getJson('/api/portal/dashboard')->assertOk()->assertJsonCount(1, 'bookings')->assertJsonMissingPath('invoices')->assertJsonMissingPath('billing');
        $this->withSession(['business_portal_user_id' => $adminId])->getJson('/api/portal/dashboard')->assertOk()->assertJsonCount(1, 'bookings')->assertJsonCount(1, 'invoices')->assertJsonPath('billing.invoice_email', 'faktura@portal.test');
    }

    public function test_business_portal_only_exposes_upcoming_active_bookings_and_allowed_active_types(): void
    {
        $customerId = DB::table('customers')->insertGetId(['display_name' => 'Afgrænset Portal ApS', 'customer_type' => 'business', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('business_portal_settings')->insert(['customer_id' => $customerId, 'portal_active' => true, 'sms_active' => false, 'allowed_inspection_types' => json_encode(['Periodisk syn']), 'requisition_requirement' => 'optional', 'change_cutoff_minutes' => 120, 'booking_horizon_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
        $portalUserId = DB::table('business_portal_users')->insertGetId(['customer_id' => $customerId, 'name' => 'Portalbruger', 'email' => 'afgraenset@example.test', 'password' => Hash::make('password123'), 'role' => 'employee', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $vehicleId = DB::table('vehicles')->insertGetId(['customer_id' => $customerId, 'registration_normalized' => 'AF12345', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([now()->subDay(), now()->addWeek()] as $startsAt) {
            DB::table('bookings')->insert(['customer_id' => $customerId, 'business_customer_id' => $customerId, 'business_portal_user_id' => $portalUserId, 'vehicle_id' => $vehicleId, 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes(20), 'inspection_type' => 'Periodisk syn', 'status' => 'confirmed', 'source' => 'business_portal', 'booking_channel' => 'business_portal', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->withSession(['business_portal_user_id' => $portalUserId])->getJson('/api/portal/session')
            ->assertOk()->assertJsonCount(1, 'bookingTypes')->assertJsonPath('bookingTypes.0.name', 'Periodisk syn');
        $this->withSession(['business_portal_user_id' => $portalUserId])->getJson('/api/portal/dashboard')
            ->assertOk()->assertJsonCount(1, 'bookings');
        $date = now()->next(CarbonImmutable::MONDAY)->toDateString();
        $this->withSession(['business_portal_user_id' => $portalUserId])->getJson("/api/portal/availability?inspection=Toldsyn&from={$date}&to={$date}")
            ->assertUnprocessable();
        $outside = now()->addDays(31)->toDateString();
        $this->withSession(['business_portal_user_id' => $portalUserId])->getJson("/api/portal/availability?inspection=Periodisk%20syn&from={$outside}&to={$outside}")
            ->assertUnprocessable();
    }

    public function test_business_portal_contact_phone_receives_business_booking_sms(): void
    {
        $customerId = DB::table('customers')->insertGetId(['display_name' => 'SMS Erhverv ApS', 'customer_type' => 'business', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('business_portal_settings')->insert(['customer_id' => $customerId, 'portal_active' => true, 'sms_active' => true, 'requisition_requirement' => 'optional', 'change_cutoff_minutes' => 120, 'booking_horizon_days' => 90, 'created_at' => now(), 'updated_at' => now()]);
        $portalUserId = DB::table('business_portal_users')->insertGetId(['customer_id' => $customerId, 'name' => 'SMS Kontakt', 'email' => 'sms-kontakt@example.test', 'phone' => '20 12 34 56', 'password' => Hash::make('password123'), 'role' => 'employee', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $date = now()->next(CarbonImmutable::MONDAY)->toDateString();

        $bookingId = $this->withSession(['business_portal_user_id' => $portalUserId])->postJson('/api/portal/bookings', [
            'plate' => 'SM12345', 'vehicle' => 'Ford Transit', 'date' => $date, 'time' => '08:00',
            'inspection' => 'Periodisk syn', 'contactName' => 'SMS Kontakt',
        ])->assertCreated()->json('booking.id');

        $this->assertDatabaseHas('sms_messages', ['booking_id' => $bookingId, 'kind' => 'confirmation', 'template_code' => 'BUSINESS_BOOKING_CONFIRMATION', 'recipient_hash' => hash('sha256', '+4520123456')]);
        $this->assertDatabaseHas('sms_messages', ['booking_id' => $bookingId, 'kind' => 'reminder', 'template_code' => 'BUSINESS_BOOKING_REMINDER']);
    }

    public function test_portal_users_can_receive_reset_links_and_be_deleted_safely(): void
    {
        $customerId = DB::table('customers')->insertGetId(['display_name' => 'Brugeradministration ApS', 'customer_type' => 'business', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('business_portal_settings')->insert(['customer_id' => $customerId, 'portal_active' => true, 'sms_active' => false, 'requisition_requirement' => 'optional', 'change_cutoff_minutes' => 120, 'booking_horizon_days' => 90, 'created_at' => now(), 'updated_at' => now()]);

        $adminId = $this->actingAs($this->user)->postJson('/api/business-portal', [
            'type' => 'user', 'customerId' => $customerId, 'name' => 'Første administrator',
            'email' => 'forste-admin@example.test', 'password' => 'abc123', 'role' => 'admin',
        ])->assertCreated()->json('id');
        $employeeId = $this->actingAs($this->user)->postJson('/api/business-portal', [
            'type' => 'user', 'customerId' => $customerId, 'name' => 'Portalmedarbejder',
            'email' => 'medarbejder@example.test', 'password' => 'let123', 'role' => 'employee',
        ])->assertCreated()->json('id');

        $this->actingAs($this->user)->withHeader('x-public-origin', 'http://localhost:4317')->postJson('/api/business-portal', [
            'type' => 'user_password_reset', 'customerId' => $customerId, 'userId' => $employeeId,
        ])->assertOk()->assertJsonPath('message', 'Nulstillingslinket er sendt til medarbejder@example.test');
        $this->assertDatabaseHas('business_portal_password_resets', ['user_id' => $employeeId, 'used_at' => null]);

        $this->actingAs($this->user)->deleteJson('/api/business-portal/users/'.$adminId)
            ->assertConflict()->assertJsonPath('error', 'Opret en anden administrator, før den sidste administrator slettes');
        $this->actingAs($this->user)->deleteJson('/api/business-portal/users/'.$employeeId)->assertOk();
        $this->assertDatabaseMissing('business_portal_users', ['id' => $employeeId]);
        $this->assertDatabaseMissing('business_portal_password_resets', ['user_id' => $employeeId]);

        $rawToken = 'test-token-for-six-character-password';
        DB::table('business_portal_password_resets')->insert([
            'user_id' => $adminId, 'token_hash' => hash('sha256', $rawToken),
            'expires_at' => now()->addHour(), 'created_at' => now(),
        ]);
        $this->postJson('/api/portal/reset-password', [
            'email' => 'forste-admin@example.test', 'token' => $rawToken,
            'password' => 'abc123', 'password_confirmation' => 'abc123',
        ])->assertOk()->assertJsonPath('message', 'Adgangskoden er ændret. Du kan nu logge ind.');
        $this->assertTrue(Hash::check('abc123', DB::table('business_portal_users')->where('id', $adminId)->value('password')));
    }

    public function test_absence_removes_employee_from_booking_capacity(): void
    {
        $date = now()->next('Monday')->toDateString();
        $employeeId = DB::table('employees')->where('booking_capacity', true)->value('id');
        DB::table('employee_absences')->insert(['employee_id' => $employeeId, 'kind' => 'Ferie', 'date_from' => $date, 'date_to' => $date, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->user)->getJson('/api/calendar/week?start='.$date)
            ->assertOk()->assertJsonPath('days.0.totalSlots', 0)->assertJsonPath('days.0.staffedInspectors', 0);
        $this->actingAs($this->user)->postJson('/api/bookings', ['customer' => 'Fiktiv Kunde', 'customerType' => 'business', 'plate' => 'XY12345', 'vehicle' => 'Ford Transit', 'date' => $date, 'time' => '09:20', 'inspection' => 'Periodisk syn', 'status' => 'confirmed'])->assertConflict();
    }

    public function test_dmr_lookup_is_mapped_without_exposing_token(): void
    {
        config()->set('services.dmr.base_url', 'http://dmr.test');
        config()->set('services.dmr.token', 'secret-test-token');
        Http::fake(['dmr.test/*' => Http::response(['found' => true, 'source' => 'dmr-nas', 'vehicle' => ['registration' => 'EN 48 111', 'make' => 'Ford', 'model' => 'Transit']], 200)]);

        $this->actingAs($this->user)->getJson('/api/vehicles/lookup?plate=EN48111')
            ->assertOk()->assertJsonPath('found', true)->assertJsonPath('vehicle.make', 'Ford')->assertJsonMissing(['secret-test-token']);
    }

    public function test_import_validation_detects_duplicates_without_writing(): void
    {
        $this->actingAs($this->user)->postJson('/api/imports/validate', ['records' => [
            ['sourceReference' => 'syn:1', 'registration' => 'AB12345'],
            ['sourceReference' => 'syn:1', 'registration' => 'AB12345'],
        ]])->assertOk()->assertJsonPath('valid', 1)->assertJsonPath('writes', 0)->assertJsonPath('issues.0.code', 'duplicate_source');

        $this->assertDatabaseCount('audit_events', 0);
    }
}
