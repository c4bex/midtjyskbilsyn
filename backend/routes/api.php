<?php

use App\Http\Controllers\OperationsController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::post('/login', function () {
        $credentials = request()->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (!Auth::attempt($credentials)) return response()->json(['error' => 'Forkert e-mail eller adgangskode'], 401);
        request()->session()->regenerate();
        return response()->json(['user' => Auth::user()->only(['id', 'name', 'email'])]);
    })->middleware('throttle:5,1');
    Route::get('/session', fn () => response()->json(['authenticated' => Auth::check(), 'user' => Auth::user()?->only(['id', 'name', 'email'])]));
    Route::post('/logout', function () { Auth::logout(); request()->session()->invalidate(); request()->session()->regenerateToken(); return response()->json(['ok' => true]); });
});

Route::middleware(['web', 'api.token', 'throttle:120,1'])->group(function () {
    Route::get('/health', [OperationsController::class, 'health']);
    Route::get('/bookings', [OperationsController::class, 'bookings']);
    Route::post('/bookings', [OperationsController::class, 'createBooking'])->middleware('permission:bookings.write');
    Route::patch('/bookings/{booking}', [OperationsController::class, 'updateBooking'])->middleware('permission:bookings.write');
    Route::delete('/bookings/{booking}', fn (int $booking) => DB::table('bookings')->where('id', $booking)->update(['status' => 'cancelled', 'updated_at' => now()]) ? response()->json(['ok' => true]) : response()->json(['error' => 'Bookingen findes ikke'], 404))->middleware('permission:bookings.write');
    Route::get('/customers', [OperationsController::class, 'customers']);
    Route::get('/vehicles/lookup', [OperationsController::class, 'vehicleLookup']);
    Route::get('/calendar/week', [OperationsController::class, 'calendarWeek']);
    Route::get('/availability', [OperationsController::class, 'availability']);
    Route::patch('/availability', [OperationsController::class, 'updateAvailability'])->middleware('permission:settings.write');
    Route::post('/availability', [OperationsController::class, 'createClosure'])->middleware('permission:settings.write');
    Route::delete('/availability/{rule}', [OperationsController::class, 'deleteClosure'])->middleware('permission:settings.write');
    Route::get('/employees', [OperationsController::class, 'employees']);
    Route::post('/employees', [OperationsController::class, 'updateEmployee'])->middleware('permission:employees.write');
    Route::get('/invoices', [OperationsController::class, 'invoices']);
    Route::patch('/invoices', [OperationsController::class, 'updateInvoice'])->middleware('permission:invoices.write');
    Route::get('/audit', [OperationsController::class, 'auditEvents']);
    Route::get('/imports', [OperationsController::class, 'imports']);
    Route::post('/imports/validate', [OperationsController::class, 'validateImport'])->middleware('permission:imports.write');
    Route::get('/sms/queue', [OperationsController::class, 'smsQueue']);
});
