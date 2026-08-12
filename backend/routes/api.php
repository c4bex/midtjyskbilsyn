<?php

use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\OperationsController;
use App\Http\Middleware\Permission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

$sessionPayload = static function (): array {
    $user = Auth::user();
    $employee = $user ? DB::table('employees')->where('user_id', $user->id)->first() : null;
    $permissionRows = $employee ? DB::table('employee_permissions')->where('employee_id', $employee->id)->get() : collect();
    $ownerEmail = (string) config('app.seed_admin_email', '');
    $isOwner = $employee?->role === 'Teknisk ansvarlig / Ejer'
        || ($ownerEmail !== '' && $user?->email === $ownerEmail);
    $permissions = $isOwner
        ? array_keys(Permission::catalog())
        : ($permissionRows->isNotEmpty() ? $permissionRows->where('allowed', true)->pluck('permission_key')->values()->all() : Permission::rolePermissions((string) ($employee?->role ?? '')));

    return ['user' => $user?->only(['id', 'name', 'email']), 'employee' => $employee ? ['id' => (string) $employee->id, 'role' => $employee->role] : null, 'permissions' => $permissions];
};

Route::middleware('web')->group(function () use ($sessionPayload) {
    Route::post('/login', function () use ($sessionPayload) {
        $credentials = request()->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($credentials, request()->boolean('remember'))) {
            return response()->json(['error' => 'Forkert e-mail eller adgangskode'], 401);
        }
        request()->session()->regenerate();
        if (Schema::hasColumn('users', 'last_login_at')) {
            DB::table('users')->where('id', Auth::id())->update(['last_login_at' => now()]);
        }

        return response()->json($sessionPayload());
    })->middleware('throttle:5,1');
    Route::get('/session', fn () => response()->json(['authenticated' => Auth::check(), ...$sessionPayload()]));
    Route::post('/forgot-password', [OperationsController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('/reset-password', [OperationsController::class, 'resetPassword'])->middleware('throttle:10,1');
    Route::post('/logout', function () {
        Auth::logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();

        return response()->json(['ok' => true]);
    });
});

// Offentlig privatbooking: ingen internt login eller API-token, kun ratebegrænsning.
Route::middleware(['web', 'throttle:30,1'])->prefix('public')->group(function () {
    Route::get('/config', [OperationsController::class, 'publicConfig']);
    Route::get('/availability', [OperationsController::class, 'publicAvailability']);
    Route::get('/vehicle-lookup', [OperationsController::class, 'publicVehicleLookup'])->middleware('throttle:20,1');
    Route::post('/bookings', [OperationsController::class, 'publicCreateBooking']);
    Route::get('/bookings/manage', [OperationsController::class, 'publicManagedBooking']);
    Route::patch('/bookings/manage', [OperationsController::class, 'publicUpdateManagedBooking'])->middleware('throttle:10,1');
    Route::post('/bookings/lookup', [OperationsController::class, 'publicLookupBooking'])->middleware('throttle:8,1');
});

// Ekstern branchekundeportal: separat session, men samme kalender og bookingmotor.
Route::middleware(['web', 'throttle:60,1'])->prefix('portal')->group(function () {
    Route::post('/login', [OperationsController::class, 'businessPortalLogin'])->middleware('throttle:10,1');
    Route::post('/forgot-password', [OperationsController::class, 'businessPortalForgotPassword'])->middleware('throttle:5,1');
    Route::post('/reset-password', [OperationsController::class, 'businessPortalResetPassword'])->middleware('throttle:10,1');
    Route::get('/session', [OperationsController::class, 'businessPortalSession']);
    Route::post('/logout', [OperationsController::class, 'businessPortalLogout']);
    Route::get('/dashboard', [OperationsController::class, 'businessPortalDashboard']);
    Route::get('/availability', [OperationsController::class, 'businessPortalAvailability']);
    Route::post('/bookings', [OperationsController::class, 'businessPortalCreateBooking']);
    Route::patch('/bookings/{booking}', [OperationsController::class, 'businessPortalUpdateBooking']);
    Route::delete('/bookings/{booking}', [OperationsController::class, 'businessPortalDeleteBooking']);
});

Route::middleware(['web', 'api.token', 'throttle:120,1'])->group(function () {
    Route::get('/health', [OperationsController::class, 'health'])->middleware('permission:audit.read');
    Route::get('/notifications', [OperationsController::class, 'notifications']);
    Route::post('/notifications/read-all', [OperationsController::class, 'readAllNotifications']);
    Route::patch('/notifications/{notification}', [OperationsController::class, 'updateNotification']);
    Route::get('/bookings', [OperationsController::class, 'bookings'])->middleware('permission:bookings.read');
    Route::post('/bookings', [OperationsController::class, 'createBooking'])->middleware('permission:bookings.write');
    Route::patch('/bookings/{booking}', [OperationsController::class, 'updateBooking'])->middleware('permission:bookings.write');
    Route::delete('/bookings/{booking}', [OperationsController::class, 'deleteBooking'])->middleware('permission:bookings.write');
    Route::get('/customers', [OperationsController::class, 'customers'])->middleware('permission:customers.read');
    Route::get('/search', [OperationsController::class, 'search'])->middleware('permission:bookings.read');
    Route::patch('/customers/{customer}/billing', [OperationsController::class, 'updateCustomerBilling'])->middleware('permission:customers.write');
    Route::get('/vehicles/lookup', [OperationsController::class, 'vehicleLookup'])->middleware('permission:customers.read');
    Route::get('/calendar/week', [OperationsController::class, 'calendarWeek'])->middleware('permission:bookings.read');
    Route::get('/planning', [OperationsController::class, 'planning']);
    Route::get('/planning/v2/day', [OperationsController::class, 'capacityDayPlan'])->middleware('permission:bookings.read');
    Route::patch('/planning/v2/profiles/{profile}', [OperationsController::class, 'updateCapacityProfileV2'])->middleware('permission:capacity.manage');
    Route::post('/planning/v2/recurring-buffers', [OperationsController::class, 'createRecurringBufferV2'])->middleware('permission:capacity.manage');
    Route::patch('/planning/v2/recurring-buffers/{buffer}', [OperationsController::class, 'updateRecurringBufferV2'])->middleware('permission:capacity.manage');
    Route::delete('/planning/v2/recurring-buffers/{buffer}', [OperationsController::class, 'deleteRecurringBufferV2'])->middleware('permission:capacity.manage');
    Route::post('/planning/v2/overrides', [OperationsController::class, 'createScheduleOverrideV2'])->middleware('permission:capacity.manage');
    Route::delete('/planning/v2/overrides/{override}', [OperationsController::class, 'deleteScheduleOverrideV2'])->middleware('permission:capacity.manage');
    Route::patch('/planning/inspection-types/{inspectionType}', [OperationsController::class, 'updateInspectionType'])->middleware('permission:settings.write');
    Route::patch('/planning/profiles/{profile}', [OperationsController::class, 'updateCalendarProfile'])->middleware('permission:settings.write');
    Route::patch('/planning/days/{date}', [OperationsController::class, 'updatePlanningDay'])->middleware('permission:settings.write');
    Route::post('/planning/buffers', [OperationsController::class, 'createBuffer'])->middleware('permission:settings.write');
    Route::delete('/planning/buffers/{buffer}', [OperationsController::class, 'deleteBuffer'])->middleware('permission:settings.write');
    Route::get('/availability', [OperationsController::class, 'availability']);
    Route::get('/availability/holiday-suggestions', [OperationsController::class, 'holidaySuggestions']);
    Route::post('/availability/holiday-suggestions/apply', [OperationsController::class, 'applyHolidaySuggestions'])->middleware('permission:settings.write');
    Route::patch('/availability', [OperationsController::class, 'updateAvailability'])->middleware('permission:settings.write');
    Route::post('/availability', [OperationsController::class, 'createClosure'])->middleware('permission:settings.write');
    Route::delete('/availability/{rule}', [OperationsController::class, 'deleteClosure'])->middleware('permission:settings.write');
    Route::get('/employees', [OperationsController::class, 'employees'])->middleware('permission:employees.read');
    Route::post('/employees', [OperationsController::class, 'updateEmployee']);
    Route::get('/business-portal/companies', [OperationsController::class, 'businessPortalCompanies'])->middleware('permission:settings.write');
    Route::post('/business-portal', [OperationsController::class, 'updateBusinessPortal'])->middleware('permission:settings.write');
    Route::delete('/business-portal/users/{portalUser}', [OperationsController::class, 'deleteBusinessPortalUser'])->middleware('permission:settings.write');
    Route::get('/invoices', [OperationsController::class, 'invoices'])->middleware('permission:invoices.read');
    Route::patch('/invoices', [OperationsController::class, 'updateInvoice'])->middleware('permission:invoices.write');
    Route::post('/invoices/approve', [OperationsController::class, 'approveInvoices'])->middleware('permission:invoices.approve');
    Route::get('/audit', [OperationsController::class, 'auditEvents'])->middleware('permission:audit.read');
    Route::get('/imports', [OperationsController::class, 'imports'])->middleware('permission:imports.read');
    Route::post('/imports/validate', [OperationsController::class, 'validateImport'])->middleware('permission:imports.write');
    Route::get('/sms/queue', [OperationsController::class, 'smsQueue'])->middleware('permission:settings.write');
    Route::get('/sms/settings', [OperationsController::class, 'smsSettings'])->middleware('permission:settings.write');
    Route::patch('/sms/settings', [OperationsController::class, 'updateSmsSettings'])->middleware('permission:settings.write');
    Route::get('/sms/templates', [OperationsController::class, 'smsTemplates'])->middleware('permission:settings.write');
    Route::patch('/sms/templates/{code}', [OperationsController::class, 'updateSmsTemplate'])->middleware('permission:settings.write');
    Route::post('/sms/templates/{code}/reset', [OperationsController::class, 'resetSmsTemplate'])->middleware('permission:settings.write');
    Route::get('/sms/messages', [OperationsController::class, 'smsMessages'])->middleware('permission:settings.write');
    Route::get('/customers/{customer}/sms-preferences', [OperationsController::class, 'businessSmsPreferences'])->middleware('permission:customers.read');
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
