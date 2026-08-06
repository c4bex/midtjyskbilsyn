<?php

use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\OperationsController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::post('/login', function () {
        $credentials = request()->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($credentials)) {
            return response()->json(['error' => 'Forkert e-mail eller adgangskode'], 401);
        }
        request()->session()->regenerate();

        return response()->json(['user' => Auth::user()->only(['id', 'name', 'email'])]);
    })->middleware('throttle:5,1');
    Route::get('/session', fn () => response()->json(['authenticated' => Auth::check(), 'user' => Auth::user()?->only(['id', 'name', 'email'])]));
    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return response()->json(['ok' => true]);
    });
});

Route::middleware(['web', 'api.token', 'throttle:120,1'])->group(function () {
    Route::get('/health', [OperationsController::class, 'health']);
    Route::get('/bookings', [OperationsController::class, 'bookings']);
    Route::post('/bookings', [OperationsController::class, 'createBooking'])->middleware('permission:bookings.write');
    Route::patch('/bookings/{booking}', [OperationsController::class, 'updateBooking'])->middleware('permission:bookings.write');
    Route::delete('/bookings/{booking}', [OperationsController::class, 'deleteBooking'])->middleware('permission:bookings.write');
    Route::get('/customers', [OperationsController::class, 'customers']);
    Route::get('/search', [OperationsController::class, 'search']);
    Route::patch('/customers/{customer}/billing', [OperationsController::class, 'updateCustomerBilling'])->middleware('permission:customers.write');
    Route::get('/vehicles/lookup', [OperationsController::class, 'vehicleLookup']);
    Route::get('/calendar/week', [OperationsController::class, 'calendarWeek']);
    Route::get('/planning', [OperationsController::class, 'planning']);
    Route::patch('/planning/inspection-types/{inspectionType}', [OperationsController::class, 'updateInspectionType'])->middleware('permission:settings.write');
    Route::patch('/planning/profiles/{profile}', [OperationsController::class, 'updateCalendarProfile'])->middleware('permission:settings.write');
    Route::patch('/planning/days/{date}', [OperationsController::class, 'updatePlanningDay'])->middleware('permission:settings.write');
    Route::post('/planning/buffers', [OperationsController::class, 'createBuffer'])->middleware('permission:settings.write');
    Route::delete('/planning/buffers/{buffer}', [OperationsController::class, 'deleteBuffer'])->middleware('permission:settings.write');
    Route::get('/availability', [OperationsController::class, 'availability']);
    Route::patch('/availability', [OperationsController::class, 'updateAvailability'])->middleware('permission:settings.write');
    Route::post('/availability', [OperationsController::class, 'createClosure'])->middleware('permission:settings.write');
    Route::delete('/availability/{rule}', [OperationsController::class, 'deleteClosure'])->middleware('permission:settings.write');
    Route::get('/employees', [OperationsController::class, 'employees']);
    Route::post('/employees', [OperationsController::class, 'updateEmployee'])->middleware('permission:employees.write');
    Route::get('/invoices', [OperationsController::class, 'invoices']);
    Route::patch('/invoices', [OperationsController::class, 'updateInvoice'])->middleware('permission:invoices.write');
    Route::post('/invoices/approve', [OperationsController::class, 'approveInvoices'])->middleware('permission:invoices.write');
    Route::get('/audit', [OperationsController::class, 'auditEvents']);
    Route::get('/imports', [OperationsController::class, 'imports']);
    Route::post('/imports/validate', [OperationsController::class, 'validateImport'])->middleware('permission:imports.write');
    Route::get('/sms/queue', [OperationsController::class, 'smsQueue']);
    Route::get('/sms/settings', [OperationsController::class, 'smsSettings']);
    Route::patch('/sms/settings', [OperationsController::class, 'updateSmsSettings'])->middleware('permission:settings.write');
    Route::get('/sms/templates', [OperationsController::class, 'smsTemplates']);
    Route::patch('/sms/templates/{code}', [OperationsController::class, 'updateSmsTemplate'])->middleware('permission:settings.write');
    Route::post('/sms/templates/{code}/reset', [OperationsController::class, 'resetSmsTemplate'])->middleware('permission:settings.write');
    Route::get('/sms/messages', [OperationsController::class, 'smsMessages']);
    Route::get('/customers/{customer}/sms-preferences', [OperationsController::class, 'businessSmsPreferences']);
    Route::patch('/customers/{customer}/sms-preferences', [OperationsController::class, 'updateBusinessSmsPreferences'])->middleware('permission:customers.write');
    Route::get('/ai/bootstrap', [AiAssistantController::class, 'bootstrap'])->middleware('permission:ai.use');
    Route::post('/ai/conversations', [AiAssistantController::class, 'createConversation'])->middleware('permission:ai.use');
    Route::get('/ai/conversations/{conversation}', [AiAssistantController::class, 'showConversation'])->middleware('permission:ai.use');
    Route::post('/ai/conversations/{conversation}/messages', [AiAssistantController::class, 'ask'])->middleware('permission:ai.use');
    Route::get('/ai/documents', [AiAssistantController::class, 'documents'])->middleware('permission:ai.use');
    Route::post('/ai/documents', [AiAssistantController::class, 'uploadDocument'])->middleware('permission:ai.documents.write');
    Route::patch('/ai/documents/{document}', [AiAssistantController::class, 'updateDocument'])->middleware('permission:ai.documents.write');
    Route::post('/ai/documents/{document}/reprocess', [AiAssistantController::class, 'reprocessDocument'])->middleware('permission:ai.documents.write');
    Route::get('/ai/documents/{document}/file', [AiAssistantController::class, 'downloadDocument'])->middleware('permission:ai.use');
    Route::get('/ai/investigations', [AiAssistantController::class, 'investigations'])->middleware('permission:ai.investigations.read');
    Route::post('/ai/investigations', [AiAssistantController::class, 'createInvestigation'])->middleware('permission:ai.investigations.write');
    Route::post('/ai/investigations/{investigation}/arvo', [AiAssistantController::class, 'sendToArvo'])->middleware('permission:ai.arvo.send');
});
