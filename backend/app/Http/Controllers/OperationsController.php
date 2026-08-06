<?php

namespace App\Http\Controllers;

use App\Services\DmrLookupService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OperationsController extends Controller
{
    public function health(): JsonResponse
    {
        try {
            DB::connection()->getPdo();

            return response()->json(['status' => 'ok', 'checkedAt' => now()->toIso8601String(), 'database' => 'ok', 'integrations' => ['dmr' => filled(config('services.dmr.base_url')), 'gatewayapi' => false, 'dinero' => false, 'synsprogram' => false]]);
        } catch (\Throwable) {
            return response()->json(['status' => 'degraded', 'checkedAt' => now()->toIso8601String(), 'database' => 'unavailable'], 503);
        }
    }

    public function bookings(Request $request): JsonResponse
    {
        $date = $request->string('date')->toString() ?: now()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['error' => 'Ugyldig dato'], 400);
        }
        $rows = DB::table('bookings')->leftJoin('customers', 'customers.id', '=', 'bookings.customer_id')->join('vehicles', 'vehicles.id', '=', 'bookings.vehicle_id')
            ->whereDate('bookings.starts_at', $date)->where('bookings.status', '!=', 'cancelled')->orderBy('bookings.starts_at')
            ->get(['bookings.id', 'bookings.starts_at', 'bookings.slot_count', 'bookings.inspection_type', 'bookings.status', 'customers.display_name', 'customers.customer_type', 'vehicles.registration_normalized', 'vehicles.make', 'vehicles.model']);
        $bookings = $rows->map(fn ($row) => [
            'id' => (string) $row->id, 'date' => $date, 'time' => CarbonImmutable::parse($row->starts_at)->format('H:i'), 'slotCount' => (int) ($row->slot_count ?? 1),
            'customer' => $row->display_name ?? 'Ukendt kunde', 'customerType' => $row->customer_type ?? 'private',
            'plate' => $this->formatPlate($row->registration_normalized), 'vehicle' => trim(($row->make ?? '').' '.($row->model ?? '')),
            'inspection' => $row->inspection_type, 'status' => $row->status,
        ]);
        $availability = $this->availabilityForDate($date, $request->string('inspection')->toString() ?: null);

        return response()->json([
            'bookings' => $bookings,
            'availableSlots' => $availability['availableSlots'],
            'slotCapacities' => $availability['slotCapacities'],
            'staffedInspectors' => $availability['staffedInspectors'],
        ]);
    }

    public function createBooking(Request $request): JsonResponse
    {
        $input = $this->bookingInput($request);
        if ($input instanceof JsonResponse) {
            return $input;
        }

        return DB::transaction(function () use ($input) {
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $input['date'].' '.$input['time']);
            DB::table('availability_rules')->where('weekday', $startsAt->isoWeekday())->lockForUpdate()->get();
            $definition = $this->inspectionDefinition($input['inspection']);
            $availability = $this->availabilityForDate($input['date'], $input['inspection']);
            if (! in_array($input['time'], $availability['availableSlots'], true)) {
                return response()->json(['error' => $definition['requiredSlots'] > 1 ? 'Toldsynet kræver to sammenhængende ledige tider' : 'Tidspunktet er ikke åbent eller har ikke flere ledige pladser'], 409);
            }

            $registration = $this->normalizePlate($input['plate']);
            $vehicleWords = preg_split('/\s+/', trim($input['vehicle'] ?? '')) ?: [];
            $make = array_shift($vehicleWords) ?: null;
            $model = trim(implode(' ', $vehicleWords)) ?: null;
            $vehicle = DB::table('vehicles')->where('registration_normalized', $registration)->first();
            if ($vehicle) {
                $customerId = $vehicle->customer_id ?: DB::table('customers')->insertGetId(['display_name' => $input['customer'], 'customer_type' => $input['customerType'], 'created_at' => now(), 'updated_at' => now()]);
                DB::table('customers')->where('id', $customerId)->update(['display_name' => $input['customer'], 'customer_type' => $input['customerType'], 'updated_at' => now()]);
                DB::table('vehicles')->where('id', $vehicle->id)->update(['customer_id' => $customerId, 'make' => $make, 'model' => $model, 'updated_at' => now()]);
                $vehicleId = $vehicle->id;
            } else {
                $customerId = DB::table('customers')->insertGetId(['display_name' => $input['customer'], 'customer_type' => $input['customerType'], 'created_at' => now(), 'updated_at' => now()]);
                $vehicleId = DB::table('vehicles')->insertGetId(['customer_id' => $customerId, 'registration_normalized' => $registration, 'make' => $make, 'model' => $model, 'created_at' => now(), 'updated_at' => now()]);
            }
            $id = DB::table('bookings')->insertGetId(['customer_id' => $customerId, 'vehicle_id' => $vehicleId, 'starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes($definition['requiredSlots'] * $availability['intervalMinutes']), 'slot_count' => $definition['requiredSlots'], 'inspection_type' => $input['inspection'], 'status' => $input['status'] ?? 'confirmed', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('booking.created', 'booking', $id, null, $input);
            if ($input['customerType'] === 'private') {
                DB::table('sms_messages')->insert(['booking_id' => $id, 'kind' => 'confirmation', 'status' => 'held', 'idempotency_key' => 'booking:'.$id.':confirmation', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                if ($startsAt->isAfter(now()->addDay())) {
                    DB::table('sms_messages')->insert(['booking_id' => $id, 'kind' => 'reminder', 'status' => 'held', 'idempotency_key' => 'booking:'.$id.':reminder', 'available_at' => $startsAt->subDay(), 'created_at' => now(), 'updated_at' => now()]);
                }
            }

            return response()->json(['booking' => ['id' => (string) $id]], 201);
        });
    }

    public function updateBooking(Request $request, int $booking): JsonResponse
    {
        $current = DB::table('bookings')->join('vehicles', 'vehicles.id', '=', 'bookings.vehicle_id')->leftJoin('customers', 'customers.id', '=', 'bookings.customer_id')->where('bookings.id', $booking)->first(['bookings.*', 'vehicles.registration_normalized', 'vehicles.make', 'vehicles.model', 'customers.display_name', 'customers.customer_type']);
        if (! $current) {
            return response()->json(['error' => 'Bookingen findes ikke'], 404);
        }
        if ($request->input('action') === 'cancel') {
            DB::table('bookings')->where('id', $booking)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->audit('booking.cancelled', 'booking', $booking, (array) $current, ['status' => 'cancelled']);

            return response()->json(['ok' => true]);
        }
        $input = $this->bookingInput($request);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        $result = DB::transaction(function () use ($booking, $current, $input) {
            [$make, $model] = $this->splitVehicle($input['vehicle']);
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $input['date'].' '.$input['time']);
            DB::table('availability_rules')->where('weekday', $startsAt->isoWeekday())->lockForUpdate()->get();
            $definition = $this->inspectionDefinition($input['inspection']);
            $availability = $this->availabilityForDate($input['date'], $input['inspection'], $booking);
            if (! in_array($input['time'], $availability['availableSlots'], true)) {
                return response()->json(['error' => $definition['requiredSlots'] > 1 ? 'Toldsynet kræver to sammenhængende ledige tider' : 'Det valgte tidspunkt har ikke flere ledige pladser'], 409);
            }
            DB::table('customers')->where('id', $current->customer_id)->update(['display_name' => $input['customer'], 'customer_type' => $input['customerType'], 'updated_at' => now()]);
            DB::table('vehicles')->where('id', $current->vehicle_id)->update(['registration_normalized' => $this->normalizePlate($input['plate']), 'make' => $make, 'model' => $model, 'updated_at' => now()]);
            DB::table('bookings')->where('id', $booking)->update(['starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes($definition['requiredSlots'] * $availability['intervalMinutes']), 'slot_count' => $definition['requiredSlots'], 'inspection_type' => $input['inspection'], 'updated_at' => now()]);
            $this->audit('booking.updated', 'booking', $booking, (array) $current, $input);

            return null;
        });
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['ok' => true]);
    }

    public function customers(): JsonResponse
    {
        $customers = DB::table('customers')->orderBy('display_name')->get()->map(function ($customer) {
            $vehicles = DB::table('vehicles')->where('customer_id', $customer->id)->get()->map(fn ($vehicle) => ['id' => (string) $vehicle->id, 'plate' => $this->formatPlate($vehicle->registration_normalized), 'vehicle' => trim(($vehicle->make ?? '').' '.($vehicle->model ?? ''))]);
            $history = DB::table('bookings')->where('customer_id', $customer->id)->latest('starts_at')->get()->map(fn ($booking) => ['id' => (string) $booking->id, 'date' => CarbonImmutable::parse($booking->starts_at)->format('Y-m-d'), 'time' => CarbonImmutable::parse($booking->starts_at)->format('H:i'), 'inspection' => $booking->inspection_type, 'status' => $booking->status]);

            return ['id' => (string) $customer->id, 'name' => $customer->display_name, 'customerType' => $customer->customer_type, 'vehicles' => $vehicles, 'history' => $history];
        });

        return response()->json(['customers' => $customers]);
    }

    public function vehicleLookup(Request $request, DmrLookupService $dmr): JsonResponse
    {
        $registration = $request->string('plate')->toString() ?: $request->string('registration')->toString();
        if ($this->normalizePlate($registration) === '') {
            return response()->json(['found' => false], 400);
        }
        $result = $dmr->lookup($registration);
        if (! ($result['unavailable'] ?? false)) {
            return response()->json($result);
        }
        $vehicle = DB::table('vehicles')->where('registration_normalized', $this->normalizePlate($registration))->first();
        if (! $vehicle) {
            return response()->json(['found' => false, 'source' => 'local-mysql', 'unavailable' => true]);
        }

        return response()->json(['found' => true, 'source' => 'local-mysql', 'vehicle' => ['registration' => $this->formatPlate($vehicle->registration_normalized), 'make' => $vehicle->make, 'model' => $vehicle->model], 'unavailable' => true]);
    }

    public function availability(): JsonResponse
    {
        return response()->json(['rules' => DB::table('availability_rules')->orderBy('weekday')->orderBy('date_from')->get()]);
    }

    public function updateAvailability(Request $request): JsonResponse
    {
        $data = $request->validate(['weekday' => ['required', 'integer', 'between:1,7'], 'closed' => ['nullable', 'boolean'], 'startsAt' => ['nullable', 'date_format:H:i'], 'endsAt' => ['nullable', 'date_format:H:i'], 'breakStartsAt' => ['nullable', 'date_format:H:i'], 'breakEndsAt' => ['nullable', 'date_format:H:i']]);
        DB::transaction(function () use ($data) {
            DB::table('availability_rules')->where('weekday', $data['weekday'])->whereIn('kind', ['opening_hours', 'break', 'closed_day'])->delete();
            if ($data['closed'] ?? false) {
                DB::table('availability_rules')->insert(['kind' => 'closed_day', 'weekday' => $data['weekday'], 'label' => 'Fast lukkedag', 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('availability_rules')->insert(['kind' => 'opening_hours', 'weekday' => $data['weekday'], 'starts_at' => $data['startsAt'], 'ends_at' => $data['endsAt'], 'label' => 'Normal åbningstid', 'created_at' => now(), 'updated_at' => now()]);
                if (! empty($data['breakStartsAt']) && ! empty($data['breakEndsAt'])) {
                    DB::table('availability_rules')->insert(['kind' => 'break', 'weekday' => $data['weekday'], 'starts_at' => $data['breakStartsAt'], 'ends_at' => $data['breakEndsAt'], 'label' => 'Pause', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $this->audit('availability.updated', 'availability', 'weekday-'.$data['weekday'], null, $data);
        });

        return response()->json(['ok' => true]);
    }

    public function createClosure(Request $request): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'in:holiday,vacation'], 'dateFrom' => ['required', 'date'], 'dateTo' => ['required', 'date', 'after_or_equal:dateFrom'], 'label' => ['required', 'string', 'max:160']]);
        $id = DB::table('availability_rules')->insertGetId(['kind' => $data['kind'], 'date_from' => $data['dateFrom'], 'date_to' => $data['dateTo'], 'label' => $data['label'], 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('closure.created', 'availability', $id, null, $data);

        return response()->json(['id' => (string) $id], 201);
    }

    public function deleteClosure(int $rule): JsonResponse
    {
        $current = DB::table('availability_rules')->where('id', $rule)->whereIn('kind', ['holiday', 'vacation'])->first();
        if (! $current) {
            return response()->json(['error' => 'Lukkedagen findes ikke'], 404);
        }
        DB::table('availability_rules')->where('id', $rule)->delete();
        $this->audit('closure.deleted', 'availability', $rule, (array) $current, null);

        return response()->json(['ok' => true]);
    }

    public function calendarWeek(Request $request): JsonResponse
    {
        $start = $request->string('start')->toString() ?: now()->startOfWeek()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            return response()->json(['error' => 'Ugyldig startdato'], 400);
        }
        $startDate = CarbonImmutable::parse($start);
        $days = collect(range(0, 6))->map(function ($offset) use ($startDate) {
            $date = $startDate->addDays($offset);
            $rules = DB::table('availability_rules')->where(fn ($query) => $query->where('weekday', $date->isoWeekday())->orWhere(fn ($period) => $period->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date)))->get();
            $opening = $rules->firstWhere('kind', 'opening_hours');
            $closed = ! $opening || $rules->whereIn('kind', ['closed_day', 'holiday', 'vacation'])->isNotEmpty();
            if ($closed) {
                return ['date' => $date->toDateString(), 'weekday' => $date->isoWeekday(), 'closed' => true, 'totalSlots' => 0, 'bookedSlots' => 0, 'availableSlots' => []];
            }
            $availability = $this->availabilityForDate($date->toDateString());

            return ['date' => $date->toDateString(), 'weekday' => $date->isoWeekday(), 'closed' => false, 'totalSlots' => $availability['totalCapacity'], 'bookedSlots' => $availability['bookedSlots'], 'availableCapacity' => $availability['availableCapacity'], 'availableSlots' => $availability['availableSlots'], 'staffedInspectors' => $availability['staffedInspectors']];
        });

        return response()->json(['week' => $startDate->isoWeek(), 'start' => $start, 'end' => $startDate->addDays(6)->toDateString(), 'days' => $days]);
    }

    public function employees(): JsonResponse
    {
        return response()->json(['employees' => DB::table('employees')->orderBy('display_name')->get()->map(fn ($employee) => ['id' => (string) $employee->id, 'name' => $employee->display_name, 'role' => $employee->role, 'active' => (bool) $employee->active, 'bookingCapacity' => (bool) $employee->booking_capacity]), 'absences' => DB::table('employee_absences')->orderBy('date_from')->get(), 'workRules' => DB::table('employee_work_rules')->orderBy('employee_id')->orderBy('weekday')->get()]);
    }

    public function updateEmployee(Request $request): JsonResponse
    {
        $type = $request->string('type')->toString();
        if ($type === 'employee_update') {
            $data = $request->validate(['employeeId' => ['required', 'integer', 'exists:employees,id'], 'displayName' => ['required', 'string', 'max:160'], 'role' => ['required', 'string', 'max:80'], 'active' => ['required', 'boolean'], 'bookingCapacity' => ['required', 'boolean']]);
            DB::table('employees')->where('id', $data['employeeId'])->update(['display_name' => $data['displayName'], 'role' => $data['role'], 'active' => $data['active'], 'booking_capacity' => $data['bookingCapacity'], 'updated_at' => now()]);
            $this->audit('employee.updated', 'employee', $data['employeeId'], null, $data);

            return response()->json(['ok' => true]);
        }
        if ($type === 'work_rule') {
            $data = $request->validate(['employeeId' => ['required', 'integer', 'exists:employees,id'], 'weekday' => ['required', 'integer', 'between:1,7'], 'startsAt' => ['nullable', 'date_format:H:i'], 'endsAt' => ['nullable', 'date_format:H:i'], 'working' => ['required', 'boolean'], 'cycleWeeks' => ['nullable', 'integer', 'between:1,6'], 'cycleWeek' => ['nullable', 'integer', 'between:1,6']]);
            $cycleWeeks = (int) ($data['cycleWeeks'] ?? 1);
            $cycleWeek = (int) ($data['cycleWeek'] ?? 1);
            abort_if($cycleWeek > $cycleWeeks, 422, 'Uge i rul skal være inden for rullets længde');
            DB::table('employee_work_rules')->updateOrInsert(['employee_id' => $data['employeeId'], 'weekday' => $data['weekday']], ['starts_at' => $data['startsAt'] ?? null, 'ends_at' => $data['endsAt'] ?? null, 'working' => $data['working'], 'cycle_weeks' => $cycleWeeks, 'cycle_week' => $cycleWeek, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('employee.work_rule.updated', 'employee', $data['employeeId'], null, $data);

            return response()->json(['ok' => true]);
        }
        $data = $request->validate(['employeeId' => ['required', 'integer', 'exists:employees,id'], 'kind' => ['required', 'string', 'max:32'], 'dateFrom' => ['required', 'date'], 'dateTo' => ['required', 'date', 'after_or_equal:dateFrom'], 'note' => ['nullable', 'string', 'max:255']]);
        $id = DB::table('employee_absences')->insertGetId(['employee_id' => $data['employeeId'], 'kind' => $data['kind'], 'date_from' => $data['dateFrom'], 'date_to' => $data['dateTo'], 'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('employee.absence.created', 'employee_absence', $id, null, $data);

        return response()->json(['id' => (string) $id], 201);
    }

    public function planning(Request $request): JsonResponse
    {
        $date = $request->string('date')->toString() ?: now()->toDateString();

        return response()->json([
            'inspectionTypes' => DB::table('inspection_types')->orderBy('sort_order')->get(),
            'profiles' => DB::table('calendar_profiles')->where('is_active', true)->orderBy('name')->get(),
            'profileBuffers' => DB::table('profile_buffer_rules')->where('is_active', true)->orderBy('weekday')->orderBy('starts_at')->get(),
            'buffers' => DB::table('buffer_slots')->whereDate('date', $date)->orderBy('starts_at')->get(),
            'day' => $this->planningSummary($date),
        ]);
    }

    public function updateInspectionType(Request $request, int $inspectionType): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'requiredSlots' => ['required', 'integer', 'between:1,12'],
            'isActive' => ['sometimes', 'boolean'],
        ]);
        $values = ['required_slots' => $data['requiredSlots'], 'updated_at' => now()];
        if (array_key_exists('name', $data)) {
            $values['name'] = $data['name'];
        }
        if (array_key_exists('isActive', $data)) {
            $values['is_active'] = $data['isActive'];
        }
        DB::table('inspection_types')->where('id', $inspectionType)->update($values);
        $this->audit('planning.inspection_type.updated', 'inspection_type', $inspectionType, null, $data);

        return response()->json(['inspectionType' => DB::table('inspection_types')->where('id', $inspectionType)->first()]);
    }

    public function updateCalendarProfile(Request $request, int $profile): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'firstBookingAt' => ['nullable', 'date_format:H:i'],
            'lastBookingAt' => ['nullable', 'date_format:H:i'],
            'intervalMinutes' => ['nullable', 'integer', 'in:10,20,30,40,60'],
            'capacityPerSlot' => ['nullable', 'integer', 'between:1,10'],
        ]);
        $values = collect($data)->mapWithKeys(fn ($value, $key) => [Str::snake($key) => $value])->all();
        $values['updated_at'] = now();
        DB::table('calendar_profiles')->where('id', $profile)->update($values);
        $this->audit('planning.profile.updated', 'calendar_profile', $profile, null, $data);

        return response()->json(['profile' => DB::table('calendar_profiles')->where('id', $profile)->first()]);
    }

    public function updatePlanningDay(Request $request, string $date): JsonResponse
    {
        $data = $request->validate([
            'profileId' => ['nullable', 'integer', 'exists:calendar_profiles,id'],
            'mode' => ['nullable', 'in:manual,suggested,approved'],
            'capacityOverride' => ['nullable', 'integer', 'between:1,10'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $day = CarbonImmutable::createFromFormat('Y-m-d', $date);
        abort_unless($day && $day->format('Y-m-d') === $date, 422, 'Ugyldig dato');
        $summary = $this->planningSummary($date, $data['profileId'] ?? null);
        $values = [
            'calendar_profile_id' => $data['profileId'] ?? null,
            'mode' => $data['mode'] ?? 'manual',
            'capacity_override' => $data['capacityOverride'] ?? null,
            'conflict_status' => $summary['conflictStatus'],
            'notes' => $data['notes'] ?? null,
            'updated_at' => now(),
        ];
        DB::table('daily_calendar_configurations')->updateOrInsert(['date' => $date], $values + ['created_at' => now()]);
        $this->audit('planning.day.updated', 'daily_calendar_configuration', $date, null, $values);

        return response()->json(['day' => $this->planningSummary($date)]);
    }

    public function createBuffer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'], 'startsAt' => ['required', 'date_format:H:i'],
            'endsAt' => ['required', 'date_format:H:i', 'after:startsAt'], 'reason' => ['required', 'string', 'max:160'],
            'isFixed' => ['nullable', 'boolean'], 'calendarProfileId' => ['nullable', 'integer', 'exists:calendar_profiles,id'],
        ]);
        $conflicts = DB::table('bookings')->whereDate('starts_at', $data['date'])->whereNotIn('status', ['cancelled', 'no_show'])
            ->where('starts_at', '<', $data['date'].' '.$data['endsAt'])->where('ends_at', '>', $data['date'].' '.$data['startsAt'])->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        $id = DB::table('buffer_slots')->insertGetId([
            'date' => $data['date'], 'starts_at' => $data['startsAt'], 'ends_at' => $data['endsAt'], 'reason' => $data['reason'],
            'is_fixed' => $data['isFixed'] ?? false, 'calendar_profile_id' => $data['calendarProfileId'] ?? null, 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit('planning.buffer.created', 'buffer_slot', $id, null, $data + ['conflicts' => $conflicts]);

        return response()->json(['buffer' => DB::table('buffer_slots')->where('id', $id)->first(), 'conflicts' => $conflicts], 201);
    }

    public function deleteBuffer(int $buffer): JsonResponse
    {
        $current = DB::table('buffer_slots')->where('id', $buffer)->first();
        if (! $current) {
            return response()->json(['error' => 'Buffertiden findes ikke'], 404);
        }
        DB::table('buffer_slots')->where('id', $buffer)->delete();
        $this->audit('planning.buffer.deleted', 'buffer_slot', $buffer, (array) $current, null);

        return response()->json(['ok' => true]);
    }

    public function invoices(): JsonResponse
    {
        return response()->json(['invoices' => DB::table('invoice_drafts')->orderBy('customer_name')->get()]);
    }

    public function updateInvoice(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'integer', 'exists:invoice_drafts,id'], 'description' => ['required', 'string'], 'quantity' => ['required', 'integer', 'min:1'], 'unitPriceOre' => ['required', 'integer', 'min:0'], 'status' => ['required', 'in:Klargøres,Klar til Dinero']]);
        DB::table('invoice_drafts')->where('id', $data['id'])->update(['description' => $data['description'], 'quantity' => $data['quantity'], 'unit_price_ore' => $data['unitPriceOre'], 'status' => $data['status'], 'updated_at' => now()]);
        $this->audit('invoice.updated', 'invoice_draft', $data['id'], null, $data);

        return response()->json(['ok' => true]);
    }

    public function auditEvents(): JsonResponse
    {
        return response()->json(['stationId' => 'ikast', 'events' => DB::table('audit_events')->latest()->limit(30)->get(['action', 'entity_type', 'entity_id', 'created_at as occurred_at', 'actor_id'])]);
    }

    public function imports(): JsonResponse
    {
        return response()->json(['imports' => DB::table('audit_events')->where('entity_type', 'vehicle_import_batch')->latest()->limit(50)->get()->map(fn ($event) => ['batchId' => $event->entity_id, 'status' => 'completed', 'rows' => data_get(json_decode($event->after_json ?? '{}', true), 'rows', 0), 'source' => data_get(json_decode($event->after_json ?? '{}', true), 'source', 'unknown'), 'createdAt' => $event->created_at])]);
    }

    public function validateImport(Request $request): JsonResponse
    {
        $records = $request->input('records');
        if (! is_array($records) || count($records) > 1000) {
            return response()->json(['error' => 'Import skal være en liste på højst 1.000 poster'], 400);
        }
        $seen = [];
        $valid = 0;
        $issues = [];
        foreach ($records as $index => $record) {
            $reference = trim((string) ($record['sourceReference'] ?? ''));
            $registration = $this->normalizePlate((string) ($record['registration'] ?? ''));
            if ($reference === '' || $registration === '') {
                $issues[] = ['index' => $index, 'code' => 'missing_required'];

                continue;
            }
            if (isset($seen[$reference])) {
                $issues[] = ['index' => $index, 'code' => 'duplicate_source'];

                continue;
            }
            $seen[$reference] = true;
            $valid++;
        }

        return response()->json(['valid' => $valid, 'issues' => $issues, 'writes' => 0]);
    }

    public function smsQueue(): JsonResponse
    {
        $counts = DB::table('sms_messages')->select('status', DB::raw('COUNT(*) as count'))->groupBy('status')->pluck('count', 'status');

        return response()->json(['counts' => $counts, 'total' => $counts->sum(), 'enabled' => false]);
    }

    private function bookingInput(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), ['customer' => ['required', 'string', 'max:160'], 'customerType' => ['required', 'in:private,business'], 'plate' => ['required', 'string', 'max:12'], 'vehicle' => ['nullable', 'string', 'max:200'], 'date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i'], 'inspection' => ['required', 'string', 'max:80'], 'status' => ['nullable', 'string', 'max:32']]);

        return $validator->fails() ? response()->json(['error' => 'Kunde, dato, tid og registreringsnummer skal udfyldes', 'errors' => $validator->errors()], 422) : $validator->validated();
    }

    private function audit(string $action, string $entityType, int|string $entityId, ?array $before, ?array $after): void
    {
        DB::table('audit_events')->insert(['action' => $action, 'entity_type' => $entityType, 'entity_id' => (string) $entityId, 'actor_id' => Auth::id() ? (string) Auth::id() : 'service', 'before_json' => $before ? json_encode($before) : null, 'after_json' => $after ? json_encode($after) : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function normalizePlate(string $value): string
    {
        return mb_strtoupper(preg_replace('/[^A-ZÆØÅ0-9]/u', '', $value));
    }

    private function formatPlate(string $value): string
    {
        $plate = $this->normalizePlate($value);

        return strlen($plate) === 7 ? substr($plate, 0, 2).' '.substr($plate, 2, 2).' '.substr($plate, 4) : $plate;
    }

    private function splitVehicle(string $vehicle): array
    {
        $words = preg_split('/\s+/', trim($vehicle)) ?: [];
        $make = array_shift($words) ?: null;

        return [$make, trim(implode(' ', $words)) ?: null];
    }

    private function inspectionDefinition(string $name): array
    {
        $type = DB::table('inspection_types')->where('name', $name)->where('is_active', true)->first();

        return ['name' => $name, 'requiredSlots' => max(1, (int) ($type->required_slots ?? 1))];
    }

    private function planningSummary(string $date, ?int $profileId = null): array
    {
        $configured = DB::table('daily_calendar_configurations')->where('date', $date)->first();
        $profileId ??= $configured?->calendar_profile_id;
        $profile = $profileId ? DB::table('calendar_profiles')->where('id', $profileId)->first() : null;
        $availability = $this->availabilityForDate($date);
        $staffed = $availability['staffedInspectors'];
        $expected = $profile?->capacity_per_slot;
        $conflict = 'ok';
        if ($profile && $expected !== null && $staffed === 0) {
            $conflict = 'red';
        } elseif ($profile && $expected !== null && (int) $expected !== $staffed) {
            $conflict = 'warning';
        }

        return [
            'date' => $date, 'profile' => $profile, 'profileId' => $profile?->id,
            'mode' => $configured?->mode ?? 'manual', 'conflictStatus' => $conflict,
            'staffedInspectors' => $staffed, 'capacityPerSlot' => $availability['maxCapacity'],
            'bufferCount' => count($availability['buffers']), 'bookedSlots' => $availability['bookedSlots'],
            'availableCapacity' => $availability['availableCapacity'], 'buffers' => $availability['buffers'],
        ];
    }

    private function availabilityForDate(string $date, ?string $inspection = null, ?int $excludeBookingId = null): array
    {
        $day = CarbonImmutable::parse($date);
        $rules = DB::table('availability_rules')->where(fn ($query) => $query
            ->where('weekday', $day->isoWeekday())
            ->orWhere(fn ($period) => $period->whereDate('date_from', '<=', $day)->whereDate('date_to', '>=', $day)))
            ->get();
        $configured = DB::table('daily_calendar_configurations')->where('date', $date)->first();
        $profile = $configured?->calendar_profile_id ? DB::table('calendar_profiles')->where('id', $configured->calendar_profile_id)->first() : null;
        $opening = $rules->firstWhere('kind', 'opening_hours');
        if (! $opening || $rules->whereIn('kind', ['closed_day', 'holiday', 'vacation'])->isNotEmpty()) {
            return ['availableSlots' => [], 'slotCapacities' => [], 'staffedInspectors' => 0, 'maxCapacity' => 0, 'intervalMinutes' => 20, 'totalCapacity' => 0, 'bookedSlots' => 0, 'availableCapacity' => 0, 'buffers' => []];
        }

        $interval = (int) ($profile?->interval_minutes ?? 20);
        $openingStart = substr($profile?->first_booking_at ?? $opening->starts_at, 0, 5);
        $openingEnd = substr($profile?->last_booking_at ?? $opening->ends_at, 0, 5);
        $buffers = collect($rules->where('kind', 'break'))->map(fn ($buffer) => (object) ['starts_at' => $buffer->starts_at, 'ends_at' => $buffer->ends_at, 'reason' => $buffer->label]);
        if ($profile) {
            $buffers = $buffers->concat(DB::table('profile_buffer_rules')->where('calendar_profile_id', $profile->id)->where('is_active', true)->where(fn ($query) => $query->whereNull('weekday')->orWhere('weekday', $day->isoWeekday()))->get());
        }
        $dailyBuffers = DB::table('buffer_slots')->whereDate('date', $date)->get();
        $buffers = $buffers->concat($dailyBuffers);
        $slots = $this->timeSlots($openingStart, $openingEnd, $buffers, $interval);
        $employeeIds = DB::table('employees')->where('active', true)->where('booking_capacity', true)->pluck('id');
        $absentIds = DB::table('employee_absences')->whereIn('employee_id', $employeeIds)->whereDate('date_from', '<=', $day)->whereDate('date_to', '>=', $day)->pluck('employee_id')->all();
        $cycleWeek = (($day->isoWeek() - 1) % 6) + 1;
        $workRules = DB::table('employee_work_rules')->whereIn('employee_id', $employeeIds)->where('weekday', $day->isoWeekday())->where('working', true)->get()->filter(function ($rule) use ($cycleWeek) {
            $cycleWeeks = max(1, (int) ($rule->cycle_weeks ?? 1));
            $activeCycleWeek = (($cycleWeek - 1) % $cycleWeeks) + 1;

            return (int) ($rule->cycle_week ?? 1) === $activeCycleWeek;
        })->reject(fn ($rule) => in_array($rule->employee_id, $absentIds, true));
        $staffed = $workRules->pluck('employee_id')->unique()->count();
        $maxCapacity = $configured?->capacity_override ?? $profile?->capacity_per_slot ?? $staffed;
        $maxCapacity = min($staffed, (int) $maxCapacity);
        $bookedByTime = collect();
        DB::table('bookings')->whereDate('starts_at', $day)->whereNotIn('status', ['cancelled', 'no_show'])->when($excludeBookingId, fn ($query) => $query->where('id', '!=', $excludeBookingId))->get(['starts_at', 'ends_at'])->each(function ($booking) use (&$bookedByTime, $interval) {
            $start = CarbonImmutable::parse($booking->starts_at);
            $end = CarbonImmutable::parse($booking->ends_at);
            $cursor = $start;
            while ($cursor->lt($end)) {
                $key = $cursor->format('H:i');
                $bookedByTime[$key] = ($bookedByTime[$key] ?? 0) + 1;
                $cursor = $cursor->addMinutes($interval);
            }
        });
        $slotCapacities = [];
        foreach ($slots as $slot) {
            $slotEnd = CarbonImmutable::createFromFormat('H:i', $slot)->addMinutes($interval)->format('H:i');
            $slotCapacities[$slot] = $workRules->filter(fn ($rule) => substr($rule->starts_at, 0, 5) <= $slot && substr($rule->ends_at, 0, 5) >= $slotEnd)->count();
            $slotCapacities[$slot] = min($slotCapacities[$slot], $maxCapacity);
        }
        $required = $this->inspectionDefinition($inspection ?? 'Periodisk syn')['requiredSlots'];
        $availableSlots = [];
        foreach ($slots as $index => $slot) {
            $window = array_slice($slots, $index, $required);
            if (count($window) !== $required || count(array_filter($window, fn ($value, $position) => $position > 0 && (int) CarbonImmutable::createFromFormat('H:i', $window[$position - 1])->diffInMinutes(CarbonImmutable::createFromFormat('H:i', $value)) !== $interval, ARRAY_FILTER_USE_BOTH)) > 0) {
                continue;
            }
            if (count(array_filter($window, fn ($value) => ($slotCapacities[$value] ?? 0) > ($bookedByTime[$value] ?? 0))) === $required) {
                $availableSlots[] = $slot;
            }
        }

        return ['availableSlots' => $availableSlots, 'slotCapacities' => $slotCapacities, 'staffedInspectors' => $staffed, 'maxCapacity' => $maxCapacity, 'intervalMinutes' => $interval, 'totalCapacity' => array_sum($slotCapacities), 'bookedSlots' => $bookedByTime->sum(), 'availableCapacity' => array_sum(array_map(fn ($slot) => max(0, $slotCapacities[$slot] - ($bookedByTime[$slot] ?? 0)), $slots)), 'buffers' => $buffers->values()->all()];
    }

    private function timeSlots(string $start, string $end, $breaks, int $interval = 20): array
    {
        $cursor = CarbonImmutable::createFromFormat('H:i', $start);
        $stop = CarbonImmutable::createFromFormat('H:i', $end);
        $slots = [];
        while ($cursor->addMinutes($interval)->lte($stop)) {
            $time = $cursor->format('H:i');
            $slotEnd = $cursor->addMinutes($interval)->format('H:i');
            $blocked = $breaks->contains(fn ($break) => $time < substr($break->ends_at, 0, 5) && $slotEnd > substr($break->starts_at, 0, 5));
            if (! $blocked) {
                $slots[] = $time;
            }
            $cursor = $cursor->addMinutes($interval);
        }

        return $slots;
    }
}
