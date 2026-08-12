<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CapacityPlannerV2;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CapacityPlannerV2Test extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private int $employeeId;

    private int $ikastId;

    private string $monday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->monday = now()->next(CarbonImmutable::MONDAY)->toDateString();
        $this->user = User::factory()->create();
        $this->ikastId = (int) DB::table('departments')->where('name', 'Ikast')->value('id');
        $this->employeeId = DB::table('employees')->insertGetId([
            'user_id' => $this->user->id, 'display_name' => 'V2 administrator',
            'role' => 'Teknisk ansvarlig / Ejer', 'active' => true, 'booking_capacity' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employee_departments')->insert([
            'employee_id' => $this->employeeId, 'department_id' => $this->ikastId,
            'is_primary' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('availability_rules')->insert([
            'kind' => 'opening_hours', 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00',
            'label' => 'Teståbning', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employee_work_rules')->insert([
            'employee_id' => $this->employeeId, 'department_id' => $this->ikastId, 'weekday' => 1,
            'starts_at' => '08:00', 'ends_at' => '12:00', 'working' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_one_employee_uses_three_open_blocks_and_one_internal_buffer(): void
    {
        $plan = app(CapacityPlannerV2::class)->buildDayPlan('ikast', $this->monday);

        $this->assertSame(['08:00', '08:20', '08:40'], array_slice($plan['publicAvailableSlots'], 0, 3));
        $this->assertNotContains('09:00', $plan['publicAvailableSlots']);
        $this->assertContains('09:00', $plan['internalAvailableSlots']);
        $slot = collect($plan['slots'])->firstWhere('time', '09:00');
        $this->assertSame('AUTO_BUFFER', $slot['visualType']);
        $this->assertSame('HIDDEN', $slot['publicState']);
        $this->assertSame('BUFFER', $slot['internalState']);
    }

    public function test_two_employees_remove_automatic_buffers_but_keep_one_public_start(): void
    {
        $second = DB::table('employees')->insertGetId([
            'display_name' => 'V2 synsinspektør', 'role' => 'Synsinspektør', 'active' => true,
            'booking_capacity' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('employee_departments')->insert(['employee_id' => $second, 'department_id' => $this->ikastId, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('employee_work_rules')->insert(['employee_id' => $second, 'department_id' => $this->ikastId, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00', 'working' => true, 'created_at' => now(), 'updated_at' => now()]);

        $plan = app(CapacityPlannerV2::class)->buildDayPlan('ikast', $this->monday);

        $this->assertSame(0, $plan['bufferCount']);
        $this->assertSame(12, count($plan['publicAvailableSlots']));
        $this->assertSame(1, collect($plan['slots'])->firstWhere('time', '08:00')['publicCapacity']);
        $this->assertSame(2, collect($plan['slots'])->firstWhere('time', '08:00')['internalCapacity']);
    }

    public function test_one_booking_reduces_public_count_immediately_with_two_employees(): void
    {
        $second = DB::table('employees')->insertGetId(['display_name' => 'Ekstra kapacitet', 'role' => 'Synsinspektør', 'active' => true, 'booking_capacity' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('employee_departments')->insert(['employee_id' => $second, 'department_id' => $this->ikastId, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('employee_work_rules')->insert(['employee_id' => $second, 'department_id' => $this->ikastId, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00', 'working' => true, 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->actingAs($this->user)->getJson('/api/calendar/week?start='.$this->monday)->assertOk()->json('days.0.availableCapacity');

        $this->actingAs($this->user)->postJson('/api/bookings', [
            'customer' => 'Tællekunde', 'customerType' => 'business', 'plate' => 'TA22001',
            'vehicle' => 'Ford Transit', 'date' => $this->monday, 'time' => '08:00', 'inspection' => 'Periodisk syn',
        ])->assertCreated();

        $after = $this->actingAs($this->user)->getJson('/api/calendar/week?start='.$this->monday)->assertOk();
        $this->assertSame($before - 1, $after->json('days.0.availableCapacity'));
        $this->assertNotContains('08:00', $after->json('days.0.availableSlots'));
        $this->assertContains('08:00', app(CapacityPlannerV2::class)->buildDayPlan('ikast', $this->monday)['internalAvailableSlots']);
    }

    public function test_zero_staff_closes_both_views_until_an_admin_override(): void
    {
        DB::table('employee_work_rules')->delete();
        $closed = app(CapacityPlannerV2::class)->buildDayPlan('ikast', $this->monday);
        $this->assertSame([], $closed['publicAvailableSlots']);
        $this->assertSame([], $closed['internalAvailableSlots']);

        $this->actingAs($this->user)->postJson('/api/planning/v2/overrides', [
            'location' => 'ikast', 'date' => $this->monday, 'startsAt' => '08:00', 'endsAt' => '08:20',
            'overrideType' => 'FORCE_PUBLIC_OPEN', 'reason' => 'Administrator har bekræftet ekstra kapacitet',
        ])->assertCreated();
        $open = app(CapacityPlannerV2::class)->buildDayPlan('ikast', $this->monday);
        $this->assertContains('08:00', $open['publicAvailableSlots']);
        $this->assertContains('08:00', $open['internalAvailableSlots']);
    }

    public function test_staffing_can_change_midday_and_is_isolated_by_department(): void
    {
        DB::table('employee_work_rules')->where('employee_id', $this->employeeId)->update(['ends_at' => '10:00']);
        $ikast = app(CapacityPlannerV2::class)->buildDayPlan('ikast', $this->monday);
        $this->assertSame(1, collect($ikast['slots'])->firstWhere('time', '09:40')['staffOnDuty']);
        $this->assertSame(0, collect($ikast['slots'])->firstWhere('time', '10:00')['staffOnDuty']);

        $bordingId = (int) DB::table('departments')->where('name', 'Bording')->value('id');
        $bordingEmployee = DB::table('employees')->insertGetId(['display_name' => 'Bording medarbejder', 'role' => 'Synsinspektør', 'active' => true, 'booking_capacity' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('employee_departments')->insert(['employee_id' => $bordingEmployee, 'department_id' => $bordingId, 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('employee_work_rules')->insert(['employee_id' => $bordingEmployee, 'department_id' => $bordingId, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '12:00', 'working' => true, 'created_at' => now(), 'updated_at' => now()]);
        $bording = app(CapacityPlannerV2::class)->buildDayPlan('bording', $this->monday);
        $this->assertSame(1, collect($bording['slots'])->firstWhere('time', '10:00')['staffOnDuty']);
        $this->assertSame('AUTO_BUFFER', collect($bording['slots'])->firstWhere('time', '09:00')['visualType']);
    }

    public function test_manual_rules_take_priority_and_customer_view_matches_public_availability(): void
    {
        $this->actingAs($this->user)->postJson('/api/planning/v2/recurring-buffers', [
            'location' => 'ikast', 'name' => 'Fast kontrolbuffer', 'weekdays' => [1],
            'startLocalTime' => '08:20', 'durationMinutes' => 20, 'internalBookable' => true,
        ])->assertCreated();
        $plan = app(CapacityPlannerV2::class)->buildDayPlan('ikast', $this->monday);
        $slot = collect($plan['slots'])->firstWhere('time', '08:20');
        $this->assertSame('RECURRING_BUFFER', $slot['visualType']);
        $this->assertNotContains('08:20', $plan['publicAvailableSlots']);

        $public = $this->getJson('/api/public/availability?locationId=ikast&bookingTypeId=1&from='.$this->monday.'&to='.$this->monday)->assertOk();
        $this->assertSame($plan['publicAvailableSlots'], $public->json('days.0.availableSlots'));
    }

    public function test_pause_closes_only_pause_slots_without_shifting_the_buffer_pattern(): void
    {
        DB::table('availability_rules')->insert([
            'kind' => 'break', 'weekday' => 1, 'starts_at' => '09:00', 'ends_at' => '09:20',
            'label' => 'Formiddagspause', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $plan = app(CapacityPlannerV2::class)->buildDayPlan('ikast', $this->monday);
        $pause = collect($plan['slots'])->firstWhere('time', '09:00');
        $nextBuffer = collect($plan['slots'])->firstWhere('time', '10:20');

        $this->assertSame('CLOSED', $pause['visualType']);
        $this->assertSame('BREAK', $pause['sourceType']);
        $this->assertSame('AUTO_BUFFER', $nextBuffer['visualType']);
        $this->assertSame('NORMAL', collect($plan['slots'])->firstWhere('time', '09:20')['visualType']);
        $this->assertContains('09:20', $plan['publicAvailableSlots']);
    }

    public function test_toldsyn_requires_two_contiguous_blocks_and_cannot_cross_public_buffer(): void
    {
        $plan = app(CapacityPlannerV2::class)->buildDayPlan('ikast', $this->monday, 'Toldsyn');
        $this->assertNotContains('08:40', $plan['publicAvailableSlots']);
        $this->assertContains('09:00', $plan['internalAvailableSlots']);
        $this->assertSame(2, $plan['requiredSlots']);
    }

    public function test_internal_buffer_booking_uses_the_fixed_slot_without_moving_other_buffers(): void
    {
        $response = $this->actingAs($this->user)->postJson('/api/bookings', [
            'customer' => 'Bufferkunde', 'customerType' => 'business', 'plate' => 'BF22001',
            'vehicle' => 'Ford Transit', 'date' => $this->monday, 'time' => '09:00',
            'inspection' => 'Periodisk syn', 'bufferAction' => 'use',
        ])->assertCreated();
        $bookingId = (int) $response->json('booking.id');
        $this->assertDatabaseMissing('buffer_relocations', ['booking_id' => $bookingId]);
        $this->assertDatabaseMissing('schedule_overrides', ['related_booking_id' => $bookingId]);

        $plan = app(CapacityPlannerV2::class)->buildDayPlan('ikast', $this->monday);
        $this->assertSame('AUTO_BUFFER', collect($plan['slots'])->firstWhere('time', '10:20')['visualType']);

        $this->actingAs($this->user)->deleteJson('/api/bookings/'.$bookingId)->assertOk();
        $this->assertDatabaseMissing('buffer_relocations', ['booking_id' => $bookingId]);
    }

    public function test_feature_flag_can_return_to_the_legacy_engine_without_data_loss(): void
    {
        config()->set('capacity_planner.enabled', false);
        $legacy = $this->getJson('/api/public/availability?locationId=ikast&bookingTypeId=1&from='.$this->monday.'&to='.$this->monday)->assertOk();
        $this->assertContains('09:00', $legacy->json('days.0.availableSlots'));
        $this->assertDatabaseCount('capacity_profiles_v2', 6);
    }
}
