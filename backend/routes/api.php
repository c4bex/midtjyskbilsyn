<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;

Route::middleware('web')->group(function () {
    Route::post('/login', function () {
        $credentials = request()->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (!Auth::attempt($credentials)) return response()->json(['error' => 'Forkert e-mail eller adgangskode'], 401);
        request()->session()->regenerate();
        return response()->json(['user' => Auth::user()->only(['id', 'name', 'email'])]);
    });
    Route::get('/session', function () {
        return response()->json(['authenticated' => Auth::check(), 'user' => Auth::user()?->only(['id', 'name', 'email'])]);
    });
    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
        return response()->json(['ok' => true]);
    });
});

Route::middleware('api.token')->group(function () {

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $database = 'connected';
    } catch (Throwable) {
        $database = 'unavailable';
    }

    return response()->json([
        'status' => $database === 'connected' ? 'ok' : 'degraded',
        'service' => 'midtjysk-bilsyn-api',
        'database' => $database,
    ], $database === 'connected' ? 200 : 503);
});

Route::get('/bookings', function () {
    $date = request()->date('date') ?? now()->toDateString();

    $bookings = DB::table('bookings')
        ->leftJoin('customers', 'customers.id', '=', 'bookings.customer_id')
        ->join('vehicles', 'vehicles.id', '=', 'bookings.vehicle_id')
        ->whereDate('bookings.starts_at', $date)
        ->orderBy('bookings.starts_at')
        ->get([
            'bookings.id', 'bookings.starts_at', 'bookings.ends_at',
            'bookings.inspection_type', 'bookings.status',
            'customers.display_name as customer_name',
            'customers.customer_type', 'vehicles.registration_normalized',
            'vehicles.make', 'vehicles.model',
        ]);

    return response()->json(['date' => $date, 'bookings' => $bookings]);
});

Route::post('/bookings', function () {
    $input = request()->all();
    $validator = Validator::make($input, [
        'customerName' => ['required', 'string', 'max:160'],
        'customerType' => ['required', 'in:private,business'],
        'registration' => ['required', 'string', 'max:12'],
        'make' => ['nullable', 'string', 'max:80'],
        'model' => ['nullable', 'string', 'max:120'],
        'date' => ['required', 'date_format:Y-m-d'],
        'time' => ['required', 'date_format:H:i'],
        'inspectionType' => ['required', 'string', 'max:80'],
    ]);
    if ($validator->fails()) return response()->json(['errors' => $validator->errors()], 422);

    $registration = strtoupper(preg_replace('/[^A-ZÆØÅ0-9]/u', '', $input['registration']));
    $startsAt = $input['date'].' '.$input['time'].':00';
    $customerId = DB::table('customers')->insertGetId(['display_name' => $input['customerName'], 'customer_type' => $input['customerType'], 'created_at' => now(), 'updated_at' => now()]);
    $vehicleId = DB::table('vehicles')->insertGetId(['customer_id' => $customerId, 'registration_normalized' => $registration, 'make' => $input['make'] ?? null, 'model' => $input['model'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
    $bookingId = DB::table('bookings')->insertGetId(['customer_id' => $customerId, 'vehicle_id' => $vehicleId, 'starts_at' => $startsAt, 'ends_at' => date('Y-m-d H:i:s', strtotime($startsAt.' +20 minutes')), 'inspection_type' => $input['inspectionType'], 'status' => 'confirmed', 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]);
    return response()->json(['booking' => ['id' => $bookingId]], 201);
})->middleware('permission:bookings.write');

Route::delete('/bookings/{booking}', function (int $booking) {
    $updated = DB::table('bookings')->where('id', $booking)->update(['status' => 'cancelled', 'updated_at' => now()]);
    return $updated ? response()->json(['ok' => true]) : response()->json(['error' => 'Bookingen findes ikke'], 404);
})->middleware('permission:bookings.write');

Route::get('/customers', function () {
    $customers = DB::table('customers')->orderBy('display_name')->get([
        'id', 'display_name as name', 'customer_type', 'external_reference',
    ]);
    return response()->json(['customers' => $customers]);
});

Route::get('/vehicles/lookup', function () {
    $registration = strtoupper(preg_replace('/[^A-ZÆØÅ0-9]/u', '', (string) request('registration')));
    if ($registration === '') return response()->json(['found' => false], 400);
    $vehicle = DB::table('vehicles')
        ->leftJoin('customers', 'customers.id', '=', 'vehicles.customer_id')
        ->where('vehicles.registration_normalized', $registration)
        ->first(['vehicles.registration_normalized', 'vehicles.make', 'vehicles.model', 'customers.display_name', 'customers.customer_type']);
    if (!$vehicle) return response()->json(['found' => false]);
    return response()->json(['found' => true, 'source' => 'local-mysql', 'vehicle' => [
        'registration' => $vehicle->registration_normalized,
        'make' => $vehicle->make,
        'model' => $vehicle->model,
    ], 'customer' => $vehicle->display_name ? ['name' => $vehicle->display_name, 'customerType' => $vehicle->customer_type] : null]);
});

Route::get('/imports', function () {
    $events = DB::table('audit_events')->where('entity_type', 'vehicle_import_batch')->latest()->limit(50)->get([
        'entity_id as batch_id', 'action', 'actor_id', 'after_json', 'created_at',
    ]);
    return response()->json(['imports' => $events->map(function ($event) {
        $meta = json_decode((string) $event->after_json, true) ?: [];
        return ['batchId' => $event->batch_id, 'status' => 'completed', 'rows' => $meta['rows'] ?? 0, 'source' => $meta['source'] ?? 'unknown', 'createdAt' => $event->created_at];
    })]);
});

Route::get('/employees', function () {
    return response()->json(['employees' => DB::table('employees')->where('active', true)->orderBy('display_name')->get([
        'employees.id', 'employees.user_id as userId', 'employees.display_name as displayName', 'employees.role', 'employees.email', 'employees.active',
    ])]);
});

Route::get('/access/roles', function () {
    return response()->json(['roles' => [
        'Teknisk ansvarlig / Ejer' => ['bookings.read', 'bookings.write', 'customers.write', 'imports.write', 'invoices.write', 'employees.write', 'settings.write'],
        'Synsinspektør' => ['bookings.read', 'bookings.write', 'customers.write'],
        'Bogholder / blæksprut' => ['bookings.read', 'customers.read', 'invoices.write'],
    ]]);
});

Route::get('/access/check', function () {
    $role = (string) env('BOOKING_API_ROLE', 'Teknisk ansvarlig / Ejer');
    $permission = (string) request('permission', 'bookings.read');
    $roles = [
        'Teknisk ansvarlig / Ejer' => ['bookings.read', 'bookings.write', 'customers.write', 'imports.write', 'invoices.write', 'employees.write', 'settings.write'],
        'Synsinspektør' => ['bookings.read', 'bookings.write', 'customers.write'],
        'Bogholder / blæksprut' => ['bookings.read', 'customers.read', 'invoices.write'],
    ];
    return response()->json(['role' => $role, 'permission' => $permission, 'allowed' => in_array($permission, $roles[$role] ?? [], true)]);
});

});
