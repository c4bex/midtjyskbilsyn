<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_private_booking_is_saved_with_automatic_sms_plan(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/bookings', [
            'customer' => 'Fiktiv Privatkunde', 'customerType' => 'private', 'plate' => 'AB12345',
            'vehicle' => 'Volkswagen Golf', 'date' => now()->addDays(4)->toDateString(), 'time' => '08:00',
            'inspection' => 'Periodisk syn', 'status' => 'confirmed',
        ])->assertCreated();

        $bookingId = $response->json('booking.id');
        $this->assertDatabaseHas('bookings', ['id' => $bookingId, 'status' => 'confirmed']);
        $this->assertDatabaseHas('sms_messages', ['booking_id' => $bookingId, 'kind' => 'confirmation', 'status' => 'held']);
        $this->assertDatabaseHas('sms_messages', ['booking_id' => $bookingId, 'kind' => 'reminder', 'status' => 'held']);
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
        $payload = ['customer' => 'Fiktiv Kunde', 'customerType' => 'business', 'vehicle' => 'Ford Transit', 'date' => $date, 'time' => '09:20', 'inspection' => 'Periodisk syn', 'status' => 'confirmed'];

        $this->actingAs($this->user)->postJson('/api/bookings', $payload + ['plate' => 'XY12345'])->assertCreated();
        $this->actingAs($this->user)->getJson('/api/calendar/week?start='.$date)
            ->assertOk()->assertJsonPath('days.0.staffedInspectors', 2)->assertJsonPath('days.0.availableSlots.4', '09:20');
        $this->actingAs($this->user)->postJson('/api/bookings', $payload + ['plate' => 'XY12346'])->assertCreated();
        $this->actingAs($this->user)->postJson('/api/bookings', $payload + ['plate' => 'XY12347'])->assertConflict();

        $final = $this->actingAs($this->user)->getJson('/api/calendar/week?start='.$date)->assertOk();
        $this->assertNotContains('09:20', $final->json('days.0.availableSlots'));
    }

    public function test_employee_rotation_changes_capacity_without_daily_configuration(): void
    {
        $date = '2026-08-03'; // ISO-uge 32, uge 2 i et to-ugers rul.
        $employeeId = DB::table('employees')->where('booking_capacity', true)->value('id');
        DB::table('employee_work_rules')->where('employee_id', $employeeId)->where('weekday', 1)->update(['cycle_weeks' => 2, 'cycle_week' => 1]);

        $this->actingAs($this->user)->getJson('/api/calendar/week?start='.$date)->assertOk()->assertJsonPath('days.0.staffedInspectors', 0);

        DB::table('employee_work_rules')->where('employee_id', $employeeId)->where('weekday', 1)->update(['cycle_week' => 2]);
        $this->actingAs($this->user)->getJson('/api/calendar/week?start='.$date)->assertOk()->assertJsonPath('days.0.staffedInspectors', 1);
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
