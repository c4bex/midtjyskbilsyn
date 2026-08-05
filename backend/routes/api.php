<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

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
