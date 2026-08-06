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
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return response()->json(['error' => 'Ugyldig dato'], 400);
        $rows = DB::table('bookings')->leftJoin('customers', 'customers.id', '=', 'bookings.customer_id')->join('vehicles', 'vehicles.id', '=', 'bookings.vehicle_id')
            ->whereDate('bookings.starts_at', $date)->where('bookings.status', '!=', 'cancelled')->orderBy('bookings.starts_at')
            ->get(['bookings.id', 'bookings.starts_at', 'bookings.inspection_type', 'bookings.status', 'customers.display_name', 'customers.customer_type', 'vehicles.registration_normalized', 'vehicles.make', 'vehicles.model']);
        $bookings = $rows->map(fn ($row) => [
            'id' => (string) $row->id, 'date' => $date, 'time' => CarbonImmutable::parse($row->starts_at)->format('H:i'),
            'customer' => $row->display_name ?? 'Ukendt kunde', 'customerType' => $row->customer_type ?? 'private',
            'plate' => $this->formatPlate($row->registration_normalized), 'vehicle' => trim(($row->make ?? '').' '.($row->model ?? '')),
            'inspection' => $row->inspection_type, 'status' => $row->status,
        ]);
        return response()->json(['bookings' => $bookings, 'availableSlots' => $this->availableSlots($date, $bookings->pluck('time')->all())]);
    }

    public function createBooking(Request $request): JsonResponse
    {
        $input = $this->bookingInput($request);
        if ($input instanceof JsonResponse) return $input;
        return DB::transaction(function () use ($input) {
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
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $input['date'].' '.$input['time']);
            $conflict = DB::table('bookings')->where('starts_at', $startsAt)->whereNotIn('status', ['cancelled', 'no_show'])->exists();
            if ($conflict) return response()->json(['error' => 'Tidspunktet er allerede booket'], 409);
            $id = DB::table('bookings')->insertGetId(['customer_id' => $customerId, 'vehicle_id' => $vehicleId, 'starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes(20), 'inspection_type' => $input['inspection'], 'status' => $input['status'] ?? 'confirmed', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('booking.created', 'booking', $id, null, $input);
            if ($input['customerType'] === 'private') {
                DB::table('sms_messages')->insert(['booking_id' => $id, 'kind' => 'confirmation', 'status' => 'held', 'idempotency_key' => 'booking:'.$id.':confirmation', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                if ($startsAt->isAfter(now()->addDay())) DB::table('sms_messages')->insert(['booking_id' => $id, 'kind' => 'reminder', 'status' => 'held', 'idempotency_key' => 'booking:'.$id.':reminder', 'available_at' => $startsAt->subDay(), 'created_at' => now(), 'updated_at' => now()]);
            }
            return response()->json(['booking' => ['id' => (string) $id]], 201);
        });
    }

    public function updateBooking(Request $request, int $booking): JsonResponse
    {
        $current = DB::table('bookings')->join('vehicles', 'vehicles.id', '=', 'bookings.vehicle_id')->leftJoin('customers', 'customers.id', '=', 'bookings.customer_id')->where('bookings.id', $booking)->first(['bookings.*', 'vehicles.registration_normalized', 'vehicles.make', 'vehicles.model', 'customers.display_name', 'customers.customer_type']);
        if (!$current) return response()->json(['error' => 'Bookingen findes ikke'], 404);
        if ($request->input('action') === 'cancel') {
            DB::table('bookings')->where('id', $booking)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->audit('booking.cancelled', 'booking', $booking, (array) $current, ['status' => 'cancelled']);
            return response()->json(['ok' => true]);
        }
        $input = $this->bookingInput($request);
        if ($input instanceof JsonResponse) return $input;
        DB::transaction(function () use ($booking, $current, $input) {
            [$make, $model] = $this->splitVehicle($input['vehicle']);
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $input['date'].' '.$input['time']);
            DB::table('customers')->where('id', $current->customer_id)->update(['display_name' => $input['customer'], 'customer_type' => $input['customerType'], 'updated_at' => now()]);
            DB::table('vehicles')->where('id', $current->vehicle_id)->update(['registration_normalized' => $this->normalizePlate($input['plate']), 'make' => $make, 'model' => $model, 'updated_at' => now()]);
            DB::table('bookings')->where('id', $booking)->update(['starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes(20), 'inspection_type' => $input['inspection'], 'updated_at' => now()]);
            $this->audit('booking.updated', 'booking', $booking, (array) $current, $input);
        });
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
        if ($this->normalizePlate($registration) === '') return response()->json(['found' => false], 400);
        $result = $dmr->lookup($registration);
        if (!($result['unavailable'] ?? false)) return response()->json($result);
        $vehicle = DB::table('vehicles')->where('registration_normalized', $this->normalizePlate($registration))->first();
        if (!$vehicle) return response()->json(['found' => false, 'source' => 'local-mysql', 'unavailable' => true]);
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
            if ($data['closed'] ?? false) DB::table('availability_rules')->insert(['kind' => 'closed_day', 'weekday' => $data['weekday'], 'label' => 'Fast lukkedag', 'created_at' => now(), 'updated_at' => now()]);
            else {
                DB::table('availability_rules')->insert(['kind' => 'opening_hours', 'weekday' => $data['weekday'], 'starts_at' => $data['startsAt'], 'ends_at' => $data['endsAt'], 'label' => 'Normal åbningstid', 'created_at' => now(), 'updated_at' => now()]);
                if (!empty($data['breakStartsAt']) && !empty($data['breakEndsAt'])) DB::table('availability_rules')->insert(['kind' => 'break', 'weekday' => $data['weekday'], 'starts_at' => $data['breakStartsAt'], 'ends_at' => $data['breakEndsAt'], 'label' => 'Pause', 'created_at' => now(), 'updated_at' => now()]);
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
        if (!$current) return response()->json(['error' => 'Lukkedagen findes ikke'], 404);
        DB::table('availability_rules')->where('id', $rule)->delete();
        $this->audit('closure.deleted', 'availability', $rule, (array) $current, null);
        return response()->json(['ok' => true]);
    }

    public function calendarWeek(Request $request): JsonResponse
    {
        $start = $request->string('start')->toString() ?: now()->startOfWeek()->toDateString();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) return response()->json(['error' => 'Ugyldig startdato'], 400);
        $startDate = CarbonImmutable::parse($start);
        $days = collect(range(0, 6))->map(function ($offset) use ($startDate) {
            $date = $startDate->addDays($offset);
            $rules = DB::table('availability_rules')->where(fn ($query) => $query->where('weekday', $date->isoWeekday())->orWhere(fn ($period) => $period->whereDate('date_from', '<=', $date)->whereDate('date_to', '>=', $date)))->get();
            $opening = $rules->firstWhere('kind', 'opening_hours');
            $closed = !$opening || $rules->whereIn('kind', ['closed_day', 'holiday', 'vacation'])->isNotEmpty();
            if ($closed) return ['date' => $date->toDateString(), 'weekday' => $date->isoWeekday(), 'closed' => true, 'totalSlots' => 0, 'bookedSlots' => 0, 'availableSlots' => []];
            $breaks = $rules->where('kind', 'break');
            $slots = $this->timeSlots(substr($opening->starts_at, 0, 5), substr($opening->ends_at, 0, 5), $breaks);
            $occupied = DB::table('bookings')->whereDate('starts_at', $date)->whereNotIn('status', ['cancelled', 'no_show'])->pluck('starts_at')->map(fn ($value) => CarbonImmutable::parse($value)->format('H:i'))->all();
            $available = array_values(array_diff($slots, $occupied));
            return ['date' => $date->toDateString(), 'weekday' => $date->isoWeekday(), 'closed' => false, 'totalSlots' => count($slots), 'bookedSlots' => count($slots) - count($available), 'availableSlots' => $available];
        });
        return response()->json(['week' => $startDate->isoWeek(), 'start' => $start, 'end' => $startDate->addDays(6)->toDateString(), 'days' => $days]);
    }

    public function employees(): JsonResponse
    {
        return response()->json(['employees' => DB::table('employees')->orderBy('display_name')->get()->map(fn ($employee) => ['id' => (string) $employee->id, 'name' => $employee->display_name, 'role' => $employee->role, 'active' => (bool) $employee->active]), 'absences' => DB::table('employee_absences')->orderBy('date_from')->get(), 'workRules' => DB::table('employee_work_rules')->orderBy('employee_id')->orderBy('weekday')->get()]);
    }

    public function updateEmployee(Request $request): JsonResponse
    {
        $type = $request->string('type')->toString();
        if ($type === 'employee_update') {
            $data = $request->validate(['employeeId' => ['required', 'integer', 'exists:employees,id'], 'displayName' => ['required', 'string', 'max:160'], 'role' => ['required', 'string', 'max:80'], 'active' => ['required', 'boolean']]);
            DB::table('employees')->where('id', $data['employeeId'])->update(['display_name' => $data['displayName'], 'role' => $data['role'], 'active' => $data['active'], 'updated_at' => now()]);
            $this->audit('employee.updated', 'employee', $data['employeeId'], null, $data);
            return response()->json(['ok' => true]);
        }
        if ($type === 'work_rule') {
            $data = $request->validate(['employeeId' => ['required', 'integer', 'exists:employees,id'], 'weekday' => ['required', 'integer', 'between:1,7'], 'startsAt' => ['nullable', 'date_format:H:i'], 'endsAt' => ['nullable', 'date_format:H:i'], 'working' => ['required', 'boolean']]);
            DB::table('employee_work_rules')->updateOrInsert(['employee_id' => $data['employeeId'], 'weekday' => $data['weekday']], ['starts_at' => $data['startsAt'] ?? null, 'ends_at' => $data['endsAt'] ?? null, 'working' => $data['working'], 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('employee.work_rule.updated', 'employee', $data['employeeId'], null, $data);
            return response()->json(['ok' => true]);
        }
        $data = $request->validate(['employeeId' => ['required', 'integer', 'exists:employees,id'], 'kind' => ['required', 'string', 'max:32'], 'dateFrom' => ['required', 'date'], 'dateTo' => ['required', 'date', 'after_or_equal:dateFrom'], 'note' => ['nullable', 'string', 'max:255']]);
        $id = DB::table('employee_absences')->insertGetId(['employee_id' => $data['employeeId'], 'kind' => $data['kind'], 'date_from' => $data['dateFrom'], 'date_to' => $data['dateTo'], 'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('employee.absence.created', 'employee_absence', $id, null, $data);
        return response()->json(['id' => (string) $id], 201);
    }

    public function invoices(): JsonResponse { return response()->json(['invoices' => DB::table('invoice_drafts')->orderBy('customer_name')->get()]); }

    public function updateInvoice(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'integer', 'exists:invoice_drafts,id'], 'description' => ['required', 'string'], 'quantity' => ['required', 'integer', 'min:1'], 'unitPriceOre' => ['required', 'integer', 'min:0'], 'status' => ['required', 'in:Klargøres,Klar til Dinero']]);
        DB::table('invoice_drafts')->where('id', $data['id'])->update(['description' => $data['description'], 'quantity' => $data['quantity'], 'unit_price_ore' => $data['unitPriceOre'], 'status' => $data['status'], 'updated_at' => now()]);
        $this->audit('invoice.updated', 'invoice_draft', $data['id'], null, $data);
        return response()->json(['ok' => true]);
    }

    public function auditEvents(): JsonResponse { return response()->json(['stationId' => 'ikast', 'events' => DB::table('audit_events')->latest()->limit(30)->get(['action', 'entity_type', 'entity_id', 'created_at as occurred_at', 'actor_id'])]); }
    public function imports(): JsonResponse { return response()->json(['imports' => DB::table('audit_events')->where('entity_type', 'vehicle_import_batch')->latest()->limit(50)->get()->map(fn ($event) => ['batchId' => $event->entity_id, 'status' => 'completed', 'rows' => data_get(json_decode($event->after_json ?? '{}', true), 'rows', 0), 'source' => data_get(json_decode($event->after_json ?? '{}', true), 'source', 'unknown'), 'createdAt' => $event->created_at])]); }
    public function validateImport(Request $request): JsonResponse
    {
        $records = $request->input('records');
        if (!is_array($records) || count($records) > 1000) return response()->json(['error' => 'Import skal være en liste på højst 1.000 poster'], 400);
        $seen = []; $valid = 0; $issues = [];
        foreach ($records as $index => $record) {
            $reference = trim((string) ($record['sourceReference'] ?? ''));
            $registration = $this->normalizePlate((string) ($record['registration'] ?? ''));
            if ($reference === '' || $registration === '') { $issues[] = ['index' => $index, 'code' => 'missing_required']; continue; }
            if (isset($seen[$reference])) { $issues[] = ['index' => $index, 'code' => 'duplicate_source']; continue; }
            $seen[$reference] = true; $valid++;
        }
        return response()->json(['valid' => $valid, 'issues' => $issues, 'writes' => 0]);
    }
    public function smsQueue(): JsonResponse { $counts = DB::table('sms_messages')->select('status', DB::raw('COUNT(*) as count'))->groupBy('status')->pluck('count', 'status'); return response()->json(['counts' => $counts, 'total' => $counts->sum(), 'enabled' => false]); }

    private function bookingInput(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), ['customer' => ['required', 'string', 'max:160'], 'customerType' => ['required', 'in:private,business'], 'plate' => ['required', 'string', 'max:12'], 'vehicle' => ['nullable', 'string', 'max:200'], 'date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i'], 'inspection' => ['required', 'string', 'max:80'], 'status' => ['nullable', 'string', 'max:32']]);
        return $validator->fails() ? response()->json(['error' => 'Kunde, dato, tid og registreringsnummer skal udfyldes', 'errors' => $validator->errors()], 422) : $validator->validated();
    }

    private function audit(string $action, string $entityType, int|string $entityId, ?array $before, ?array $after): void
    {
        DB::table('audit_events')->insert(['action' => $action, 'entity_type' => $entityType, 'entity_id' => (string) $entityId, 'actor_id' => Auth::id() ? (string) Auth::id() : 'service', 'before_json' => $before ? json_encode($before) : null, 'after_json' => $after ? json_encode($after) : null, 'created_at' => now(), 'updated_at' => now()]);
    }
    private function normalizePlate(string $value): string { return mb_strtoupper(preg_replace('/[^A-ZÆØÅ0-9]/u', '', $value)); }
    private function formatPlate(string $value): string { $plate = $this->normalizePlate($value); return strlen($plate) === 7 ? substr($plate, 0, 2).' '.substr($plate, 2, 2).' '.substr($plate, 4) : $plate; }
    private function splitVehicle(string $vehicle): array { $words = preg_split('/\s+/', trim($vehicle)) ?: []; $make = array_shift($words) ?: null; return [$make, trim(implode(' ', $words)) ?: null]; }
    private function availableSlots(string $date, array $occupied): array
    {
        $day = CarbonImmutable::parse($date); $rules = DB::table('availability_rules')->where(fn ($query) => $query->where('weekday', $day->isoWeekday())->orWhere(fn ($period) => $period->whereDate('date_from', '<=', $day)->whereDate('date_to', '>=', $day)))->get();
        if ($rules->whereIn('kind', ['closed_day', 'holiday', 'vacation'])->isNotEmpty() || !($opening = $rules->firstWhere('kind', 'opening_hours'))) return [];
        return array_values(array_diff($this->timeSlots(substr($opening->starts_at, 0, 5), substr($opening->ends_at, 0, 5), $rules->where('kind', 'break')), $occupied));
    }
    private function timeSlots(string $start, string $end, $breaks): array
    {
        $cursor = CarbonImmutable::createFromFormat('H:i', $start); $stop = CarbonImmutable::createFromFormat('H:i', $end); $slots = [];
        while ($cursor->lt($stop)) { $time = $cursor->format('H:i'); $blocked = $breaks->contains(fn ($break) => $time >= substr($break->starts_at, 0, 5) && $time < substr($break->ends_at, 0, 5)); if (!$blocked) $slots[] = $time; $cursor = $cursor->addMinutes(20); }
        return $slots;
    }
}
