<?php

namespace Tests\Feature;

use App\Models\User;
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
        DB::table('employees')->insert(['user_id' => $this->user->id, 'display_name' => 'Testadministrator', 'role' => 'Teknisk ansvarlig / Ejer', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (range(1, 5) as $weekday) DB::table('availability_rules')->insert(['kind' => 'opening_hours', 'weekday' => $weekday, 'starts_at' => '08:00', 'ends_at' => '16:00', 'label' => 'Normal åbningstid', 'created_at' => now(), 'updated_at' => now()]);
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
