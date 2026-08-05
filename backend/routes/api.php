<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

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
});

Route::delete('/bookings/{booking}', function (int $booking) {
    $updated = DB::table('bookings')->where('id', $booking)->update(['status' => 'cancelled', 'updated_at' => now()]);
    return $updated ? response()->json(['ok' => true]) : response()->json(['error' => 'Bookingen findes ikke'], 404);
});

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
