<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CapacityPlannerV2
{
    public const INTERVAL = 20;

    public function buildDayPlan(string $locationSlug, string $date, ?string $inspection = null, ?int $excludeBookingId = null): array
    {
        $day = CarbonImmutable::parse($date, config('capacity_planner.timezone'))->startOfDay();
        $department = $this->department($locationSlug);
        $rules = DB::table('availability_rules')->where(fn ($query) => $query
            ->where('weekday', $day->isoWeekday())
            ->orWhere(fn ($period) => $period->whereDate('date_from', '<=', $day)->whereDate('date_to', '>=', $day)))
            ->get();
        $opening = $rules->firstWhere('kind', 'opening_hours');
        $closedDay = ! $opening || $rules->whereIn('kind', ['closed_day', 'holiday', 'vacation'])->isNotEmpty();
        if (! $opening) {
            return $this->emptyPlan($locationSlug, $date, $department);
        }

        $start = substr($opening->starts_at, 0, 5);
        $end = substr($opening->ends_at, 0, 5);
        $times = $this->times($start, $end);
        $workRules = $this->workRules($department?->id, $day);
        $absences = $this->absences($workRules->pluck('employee_id')->unique(), $day);
        $profiles = DB::table('capacity_profiles_v2')->where('active', true)
            ->where(fn ($query) => $query->where('location_slug', $locationSlug)->orWhere('department_id', $department?->id))
            ->orderByDesc('priority')->get();
        $recurring = DB::table('recurring_buffer_rules')->where('active', true)
            ->where(fn ($query) => $query->where('location_slug', $locationSlug)->orWhere('department_id', $department?->id))
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhereDate('valid_from', '<=', $day))
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day))->get();
        $overrides = DB::table('schedule_overrides')->where('location_slug', $locationSlug)
            ->where('starts_at', '<', $day->addDay())->where('ends_at', '>', $day)->get();
        $legacyBuffers = DB::table('buffer_slots')->whereDate('date', $day)->get();
        $bookings = DB::table('bookings')->whereDate('starts_at', $day)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->when($department?->id, fn ($query) => $query->where(fn ($where) => $where->where('department_id', $department->id)->orWhereNull('department_id')))
            ->when($excludeBookingId, fn ($query) => $query->where('id', '!=', $excludeBookingId))->get(['id', 'starts_at', 'ends_at']);

        $breaks = $rules->where('kind', 'break');
        $slots = [];
        $segmentKey = null;
        $cyclePosition = 0;
        foreach ($times as $time) {
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.$time, config('capacity_planner.timezone'));
            $endsAt = $startsAt->addMinutes(self::INTERVAL);
            $isPast = $startsAt->lte(CarbonImmutable::now(config('capacity_planner.timezone')));
            $onBreak = $breaks->contains(fn ($rule) => $time < substr($rule->ends_at, 0, 5) && $endsAt->format('H:i') > substr($rule->starts_at, 0, 5));
            $staffIds = $this->staffAt($workRules, $absences, $startsAt, $endsAt);
            $staffing = count($staffIds);
            $profile = $this->profileAt($profiles, $staffing, $day, $time);
            // Buffermønstret er forankret i arbejdsperiodens klokkeslæt. Pauser og
            // passerede tider må derfor hverken nulstille eller forskyde mønstret.
            $currentSegment = ($profile?->id ?? 'none').':'.$staffing;
            if ($currentSegment !== $segmentKey) {
                $cyclePosition = 0;
                $segmentKey = $currentSegment;
            }
            $isAutomaticBuffer = false;
            if ($profile && $profile->auto_buffer_enabled) {
                $openCount = max(0, (int) $profile->open_slots_per_cycle);
                $bufferCount = max(0, (int) $profile->buffer_slots_per_cycle);
                $cycleLength = $openCount + $bufferCount;
                $isAutomaticBuffer = $cycleLength > 0 && ($cyclePosition % $cycleLength) >= $openCount;
                $cyclePosition++;
            }
            $slot = [
                'startsAt' => $startsAt->toIso8601String(), 'endsAt' => $endsAt->toIso8601String(), 'time' => $time,
                'publicState' => 'OPEN', 'internalState' => 'OPEN', 'visualType' => 'NORMAL',
                'sourceType' => 'STAFFING', 'sourceId' => $profile?->id, 'sourceLabel' => $profile?->name,
                'profileId' => $profile?->id, 'profileName' => $profile?->name ?? 'Ingen aktiv profil',
                'staffOnDuty' => $staffing, 'staffIds' => $staffIds,
                'publicCapacity' => min($staffing, (int) ($profile?->public_capacity_per_start ?? 1)),
                'internalCapacity' => $staffing, 'bookedCount' => 0, 'bookingIds' => [],
                'isLocked' => false, 'canBookInternally' => $staffing > 0, 'canReleasePublicly' => false,
                'canMoveBuffer' => false, 'messages' => [],
            ];

            if ($closedDay || $onBreak || $staffing === 0 || $isPast) {
                $slot['publicState'] = 'CLOSED';
                $slot['internalState'] = 'CLOSED';
                $slot['visualType'] = 'CLOSED';
                $slot['sourceType'] = $isPast ? 'PAST' : ($closedDay ? 'CLOSURE' : ($onBreak ? 'BREAK' : 'ZERO_STAFFING'));
                $slot['sourceLabel'] = $isPast ? 'Tidspunktet er passeret' : ($closedDay ? 'Lukket dag' : ($onBreak ? 'Pause' : 'Ingen synsmedarbejdere'));
                $slot['publicCapacity'] = 0;
                $slot['internalCapacity'] = 0;
                $slot['canBookInternally'] = false;
                if ($staffing === 0 && ! $closedDay && ! $onBreak) {
                    $slot['messages'][] = 'Ingen kapacitetstællende medarbejdere er på arbejde';
                }
            } elseif ($isAutomaticBuffer) {
                $slot = $this->applyBuffer($slot, 'AUTO_BUFFER', 'Automatisk buffer – profil: '.$profile->name, (bool) $profile->buffer_internal_bookable, false);
            }

            foreach ($recurring as $rule) {
                $weekdays = $this->jsonArray($rule->weekdays);
                $ruleEnd = CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.substr($rule->start_local_time, 0, 5), config('capacity_planner.timezone'))->addMinutes((int) $rule->duration_minutes);
                if (in_array($day->isoWeekday(), array_map('intval', $weekdays), true) && $startsAt->lt($ruleEnd) && $endsAt->gt($ruleEnd->subMinutes((int) $rule->duration_minutes))) {
                    $slot = $this->applyBuffer($slot, 'RECURRING_BUFFER', 'Fast buffer: '.$rule->name, (bool) $rule->internal_bookable, true, $rule->id);
                }
            }
            foreach ($legacyBuffers as $buffer) {
                if ($time < substr($buffer->ends_at, 0, 5) && $endsAt->format('H:i') > substr($buffer->starts_at, 0, 5)) {
                    $slot = $this->applyBuffer($slot, (bool) $buffer->is_fixed ? 'RECURRING_BUFFER' : 'MANUAL_BUFFER', $buffer->reason, true, true, $buffer->id);
                }
            }

            foreach ($overrides as $override) {
                $overrideStart = CarbonImmutable::parse($override->starts_at, config('capacity_planner.timezone'));
                $overrideEnd = CarbonImmutable::parse($override->ends_at, config('capacity_planner.timezone'));
                if (! $startsAt->lt($overrideEnd) || ! $endsAt->gt($overrideStart)) {
                    continue;
                }
                $slot['isLocked'] = (bool) $override->locked;
                $slot['sourceId'] = $override->id;
                $slot['sourceLabel'] = $override->reason;
                $slot['sourceType'] = 'OVERRIDE';
                if ($override->override_type === 'FORCE_PUBLIC_OPEN') {
                    $slot['publicState'] = 'OPEN';
                    $slot['internalState'] = 'OPEN';
                    $slot['visualType'] = 'NORMAL';
                    $slot['publicCapacity'] = max(1, $slot['publicCapacity']);
                    $slot['internalCapacity'] = max(1, $slot['internalCapacity']);
                    $slot['canBookInternally'] = true;
                } elseif ($override->override_type === 'FORCE_INTERNAL_ONLY') {
                    $slot['publicState'] = 'HIDDEN';
                    $slot['internalState'] = 'OPEN';
                    $slot['visualType'] = 'MANUAL_BUFFER';
                    $slot['canBookInternally'] = true;
                } elseif ($override->override_type === 'FORCE_BUFFER') {
                    $slot = $this->applyBuffer($slot, $override->buffer_type === 'MOVED' ? 'MOVED_BUFFER' : 'MANUAL_BUFFER', $override->reason, true, (bool) $override->locked, $override->id);
                } elseif ($override->override_type === 'FORCE_CLOSED') {
                    $slot['publicState'] = 'CLOSED';
                    $slot['internalState'] = 'CLOSED';
                    $slot['visualType'] = 'CLOSED';
                    $slot['canBookInternally'] = false;
                }
            }

            foreach ($bookings as $booking) {
                $bookingStart = CarbonImmutable::parse($booking->starts_at, config('capacity_planner.timezone'));
                $bookingEnd = CarbonImmutable::parse($booking->ends_at, config('capacity_planner.timezone'));
                if ($startsAt->lt($bookingEnd) && $endsAt->gt($bookingStart)) {
                    $slot['bookedCount']++;
                    $slot['bookingIds'][] = (string) $booking->id;
                }
            }
            if ($slot['bookedCount'] >= $slot['publicCapacity'] && $slot['publicCapacity'] > 0) {
                $slot['publicState'] = 'BOOKED';
            }
            if ($slot['bookedCount'] >= $slot['internalCapacity'] || $slot['internalCapacity'] === 0) {
                $slot['internalState'] = 'BOOKED';
            }
            if ($slot['bookedCount'] > 0) {
                $slot['visualType'] = 'BOOKED';
            }
            $slots[] = $slot;
        }

        $requiredSlots = $this->requiredSlots($inspection);
        $publicAvailable = $this->availableStarts($slots, $requiredSlots, 'public');
        $internalAvailable = $this->availableStarts($slots, $requiredSlots, 'internal');
        $bufferSlots = array_values(array_filter($slots, fn ($slot) => in_array($slot['internalState'], ['BUFFER', 'OPEN'], true) && str_contains($slot['visualType'], 'BUFFER')));
        $conflicts = array_values(array_filter($slots, fn ($slot) => $slot['bookedCount'] > $slot['internalCapacity'] || ($slot['bookedCount'] > 0 && $slot['staffOnDuty'] === 0)));

        return [
            'engine' => 'v2', 'location' => ['slug' => $locationSlug, 'name' => $department?->name ?? ucfirst($locationSlug), 'departmentId' => $department?->id],
            'date' => $date, 'timezone' => config('capacity_planner.timezone'), 'intervalMinutes' => self::INTERVAL,
            'requiredSlots' => $requiredSlots, 'slots' => $slots, 'availableSlots' => $publicAvailable,
            'publicAvailableSlots' => $publicAvailable, 'internalAvailableSlots' => $internalAvailable,
            'slotCapacities' => collect($slots)->mapWithKeys(fn ($slot) => [$slot['time'] => $slot['internalCapacity']])->all(),
            'staffedInspectors' => collect($slots)->max('staffOnDuty') ?? 0, 'maxCapacity' => collect($slots)->max('internalCapacity') ?? 0,
            'totalCapacity' => count($publicAvailable) + count(array_filter($slots, fn ($slot) => $slot['bookedCount'] > 0)),
            'bookedSlots' => count(array_filter($slots, fn ($slot) => $slot['bookedCount'] > 0)),
            'availableCapacity' => count($publicAvailable), 'buffers' => $bufferSlots, 'bufferCount' => count($bufferSlots),
            'closedCount' => count(array_filter($slots, fn ($slot) => $slot['publicState'] === 'CLOSED')),
            'conflicts' => $conflicts, 'profiles' => collect($slots)->pluck('profileName')->unique()->values()->all(),
        ];
    }

    public function restoreRelocatedBuffer(int $bookingId): bool
    {
        $relocations = DB::table('buffer_relocations')->where('booking_id', $bookingId)->where('status', 'ACTIVE')->get();
        if ($relocations->isEmpty()) {
            return false;
        }
        DB::table('schedule_overrides')->where('related_booking_id', $bookingId)->where('buffer_type', 'MOVED')->delete();
        DB::table('buffer_relocations')->where('booking_id', $bookingId)->where('status', 'ACTIVE')->update(['status' => 'RESTORED', 'updated_at' => now()]);

        return true;
    }

    private function applyBuffer(array $slot, string $visualType, string $label, bool $internalBookable, bool $locked, ?int $sourceId = null): array
    {
        if ($slot['publicState'] !== 'BOOKED') {
            $slot['publicState'] = 'HIDDEN';
        }
        if ($slot['internalState'] !== 'BOOKED') {
            $slot['internalState'] = $internalBookable ? 'BUFFER' : 'CLOSED';
        }
        $slot['visualType'] = $visualType;
        $slot['sourceType'] = $visualType;
        $slot['sourceId'] = $sourceId ?? $slot['sourceId'];
        $slot['sourceLabel'] = $label;
        $slot['isLocked'] = $locked;
        $slot['canBookInternally'] = $internalBookable;
        $slot['canReleasePublicly'] = true;
        $slot['canMoveBuffer'] = false;

        return $slot;
    }

    private function availableStarts(array $slots, int $required, string $scope): array
    {
        $result = [];
        foreach ($slots as $index => $slot) {
            $window = array_slice($slots, $index, $required);
            if (count($window) !== $required) {
                continue;
            }
            $contiguous = collect($window)->keys()->every(fn ($offset) => $offset === 0 || $this->minute($window[$offset]['time']) - $this->minute($window[$offset - 1]['time']) === self::INTERVAL);
            if (! $contiguous) {
                continue;
            }
            $valid = collect($window)->every(function ($part) use ($scope) {
                if ($scope === 'public') {
                    return $part['publicState'] === 'OPEN' && $part['bookedCount'] < $part['publicCapacity'];
                }

                return in_array($part['internalState'], ['OPEN', 'BUFFER'], true) && $part['canBookInternally'] && $part['bookedCount'] < $part['internalCapacity'];
            });
            if ($valid) {
                $result[] = $slot['time'];
            }
        }

        return $result;
    }

    private function workRules(?int $departmentId, CarbonImmutable $day): Collection
    {
        $ids = DB::table('employees')->where('active', true)->where('booking_capacity', true)->where('archived', false)
            ->where(function ($employees) use ($departmentId, $day) {
                $employees->whereExists(function ($query) use ($departmentId, $day) {
                    $query->selectRaw('1')->from('employee_departments')->whereColumn('employee_departments.employee_id', 'employees.id')
                        ->when($departmentId, fn ($where) => $where->where('department_id', $departmentId))
                        ->where(fn ($where) => $where->whereNull('active_from')->orWhereDate('active_from', '<=', $day))
                        ->where(fn ($where) => $where->whereNull('active_to')->orWhereDate('active_to', '>=', $day));
                })->orWhereNotExists(function ($query) {
                    $query->selectRaw('1')->from('employee_departments')->whereColumn('employee_departments.employee_id', 'employees.id');
                });
            })->pluck('id');
        $anchorFallback = CarbonImmutable::parse('2026-08-03')->startOfWeek();

        return DB::table('employee_work_rules')->whereIn('employee_id', $ids)->where('weekday', $day->isoWeekday())->where('working', true)
            ->when($departmentId, fn ($query) => $query->where(fn ($where) => $where->where('department_id', $departmentId)->orWhereNull('department_id')))
            ->get()->filter(function ($rule) use ($day, $anchorFallback) {
                if (isset($rule->active) && ! $rule->active) {
                    return false;
                }
                if ($rule->valid_from && $day->lt(CarbonImmutable::parse($rule->valid_from))) {
                    return false;
                }
                if ($rule->valid_to && $day->gt(CarbonImmutable::parse($rule->valid_to))) {
                    return false;
                }
                $cycle = max(1, (int) ($rule->cycle_weeks ?? 1));
                $anchor = $rule->anchor_monday_date ? CarbonImmutable::parse($rule->anchor_monday_date)->startOfWeek() : $anchorFallback;
                $weeksSinceAnchor = (int) round($anchor->diffInWeeks($day->startOfWeek(), false));
                $week = (($weeksSinceAnchor % $cycle) + $cycle) % $cycle + 1;

                return $week === (int) ($rule->cycle_week ?? 1);
            })->values();
    }

    private function absences(Collection $employeeIds, CarbonImmutable $day): Collection
    {
        return DB::table('employee_absences')->whereIn('employee_id', $employeeIds)->whereDate('date_from', '<=', $day)->whereDate('date_to', '>=', $day)->get();
    }

    private function staffAt(Collection $rules, Collection $absences, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $rules->filter(function ($rule) use ($absences, $start, $end) {
            $works = substr($rule->starts_at, 0, 5) <= $start->format('H:i') && substr($rule->ends_at, 0, 5) >= $end->format('H:i');
            if (! $works) {
                return false;
            }

            return ! $absences->where('employee_id', $rule->employee_id)->contains(function ($absence) use ($start, $end) {
                if ((bool) ($absence->all_day ?? true) || ! $absence->start_at || ! $absence->end_at) {
                    return true;
                }

                return CarbonImmutable::parse($absence->start_at)->lt($end) && CarbonImmutable::parse($absence->end_at)->gt($start);
            });
        })->pluck('employee_id')->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    private function profileAt(Collection $profiles, int $staffing, CarbonImmutable $day, string $time): ?object
    {
        $level = $staffing === 0 ? 'ZERO' : ($staffing === 1 ? 'ONE' : 'TWO_PLUS');
        $eligible = $profiles->filter(function ($profile) use ($level, $day, $time) {
            if (! in_array($profile->staffing_level, [$level, 'CUSTOM'], true)) {
                return false;
            }
            if ($profile->valid_from && $day->lt(CarbonImmutable::parse($profile->valid_from))) {
                return false;
            }
            if ($profile->valid_to && $day->gt(CarbonImmutable::parse($profile->valid_to))) {
                return false;
            }
            $weekdays = $this->jsonArray($profile->weekdays);
            if ($weekdays && ! in_array($day->isoWeekday(), array_map('intval', $weekdays), true)) {
                return false;
            }
            if ($profile->starts_at && $time < substr($profile->starts_at, 0, 5)) {
                return false;
            }
            if ($profile->ends_at && $time >= substr($profile->ends_at, 0, 5)) {
                return false;
            }

            return true;
        });

        return $eligible->firstWhere('staffing_level', 'CUSTOM') ?? $eligible->firstWhere('staffing_level', $level);
    }

    private function department(string $slug): ?object
    {
        return DB::table('departments')->whereRaw('LOWER(name) = ?', [mb_strtolower(str_replace('-', ' ', $slug))])->first()
            ?? DB::table('departments')->whereRaw('LOWER(name) = ?', [mb_strtolower($slug)])->first();
    }

    private function requiredSlots(?string $inspection): int
    {
        if (! $inspection) {
            return 1;
        }

        return max(1, (int) (DB::table('inspection_types')->where('name', $inspection)->where('is_active', true)->value('required_slots') ?? 1));
    }

    private function times(string $start, string $end): array
    {
        $cursor = CarbonImmutable::createFromFormat('H:i', $start);
        $stop = CarbonImmutable::createFromFormat('H:i', $end);
        $times = [];
        while ($cursor->addMinutes(self::INTERVAL)->lte($stop)) {
            $times[] = $cursor->format('H:i');
            $cursor = $cursor->addMinutes(self::INTERVAL);
        }

        return $times;
    }

    private function minute(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $hour * 60 + $minute;
    }

    private function jsonArray(?string $value): array
    {
        if (! $value) {
            return [];
        } $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function emptyPlan(string $location, string $date, ?object $department): array
    {
        return ['engine' => 'v2', 'location' => ['slug' => $location, 'name' => $department?->name ?? ucfirst($location), 'departmentId' => $department?->id], 'date' => $date, 'timezone' => config('capacity_planner.timezone'), 'intervalMinutes' => self::INTERVAL, 'requiredSlots' => 1, 'slots' => [], 'availableSlots' => [], 'publicAvailableSlots' => [], 'internalAvailableSlots' => [], 'slotCapacities' => [], 'staffedInspectors' => 0, 'maxCapacity' => 0, 'totalCapacity' => 0, 'bookedSlots' => 0, 'availableCapacity' => 0, 'buffers' => [], 'bufferCount' => 0, 'closedCount' => 0, 'conflicts' => [], 'profiles' => []];
    }
}
