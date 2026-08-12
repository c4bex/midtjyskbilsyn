<?php

namespace App\Http\Controllers;

use App\Http\Middleware\Permission;
use App\Models\User;
use App\Services\CapacityPlannerV2;
use App\Services\DanishHolidayService;
use App\Services\DmrLookupService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OperationsController extends Controller
{
    private const RESET_MESSAGE = 'Hvis der findes en aktiv konto med de indtastede oplysninger, bliver der sendt en mail med næste trin.';

    private const PUBLIC_MINIMUM_NOTICE_MINUTES = 15;

    private const PUBLIC_MAXIMUM_BOOKING_DAYS = 60;

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:160']]);
        $user = DB::table('users')->where('email', $data['email'])->first();
        if ($user) {
            try {
                $model = User::find($user->id);
                $token = Password::broker()->createToken($model);
                $url = rtrim((string) config('app.url'), '/').'/login/reset?token='.urlencode($token).'&email='.urlencode($data['email']);
                Mail::raw("Du har bedt om at nulstille din adgangskode. Åbn linket her (gyldigt i 60 minutter):\n\n{$url}", fn ($mail) => $mail->to($data['email'])->subject('Nulstil adgangskode – Midtjysk Bilsyn'));
            } catch (\Throwable) {
            }
        }

        return response()->json(['message' => self::RESET_MESSAGE]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'token' => ['required', 'string'], 'password' => ['required', 'string', 'min:8', 'confirmed']]);
        $status = Password::reset($data, function ($user, $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
        });
        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['error' => 'Linket er udløbet eller ugyldigt. Bed om et nyt link.'], 422);
        }

        return response()->json(['message' => 'Adgangskoden er ændret. Du kan nu logge ind.']);
    }

    public function publicConfig(): JsonResponse
    {
        $types = DB::table('inspection_types')->where('is_active', true)->orderBy('sort_order')->get(['id', 'name', 'required_slots'])->map(fn ($type) => ['id' => (string) $type->id, 'name' => $type->name, 'requiredSlots' => (int) $type->required_slots]);

        return response()->json(['locations' => [['id' => 'ikast', 'name' => 'Ikast', 'slug' => 'ikast', 'address' => 'Siriusvej 5, 7430 Ikast', 'publicBookingEnabled' => true]], 'bookingTypes' => $types, 'settings' => ['emailRequired' => false, 'minimumNoticeMinutes' => self::PUBLIC_MINIMUM_NOTICE_MINUTES, 'maximumBookingDays' => self::PUBLIC_MAXIMUM_BOOKING_DAYS]]);
    }

    public function publicAvailability(Request $request): JsonResponse
    {
        $data = $request->validate(['locationId' => ['nullable', 'string', 'in:ikast'], 'bookingTypeId' => ['required', 'integer', 'exists:inspection_types,id'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d']]);
        $type = DB::table('inspection_types')->where('id', $data['bookingTypeId'])->where('is_active', true)->first();
        abort_unless($type, 404, 'Bookingtypen findes ikke');
        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->startOfDay();
        $today = CarbonImmutable::today(config('app.timezone'));
        abort_if($from->lt($today), 422, 'Startdatoen må ikke ligge i fortiden');
        abort_if($to->lt($from) || $from->diffInDays($to) > 31, 422, 'Vælg højst 31 dage ad gangen');
        abort_if($to->gt($today->addDays(self::PUBLIC_MAXIMUM_BOOKING_DAYS)), 422, 'Datoen ligger uden for bookingperioden');
        $days = [];
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $availability = $this->availabilityForDate($date->toDateString(), $type->name);
            $days[] = [
                'date' => $date->toDateString(),
                'availableSlots' => $availability['availableSlots'],
                'availableTimeSlots' => count($availability['availableSlots']),
                'availablePlaces' => $availability['availableCapacity'],
                'concurrentCapacity' => $availability['maxCapacity'],
                'staffOnDuty' => $availability['staffedInspectors'],
                'availableCount' => count($availability['availableSlots']),
            ];
        }

        return response()->json(['bookingType' => ['id' => (string) $type->id, 'name' => $type->name, 'requiredSlots' => (int) $type->required_slots], 'days' => $days]);
    }

    public function publicCreateBooking(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32'], 'email' => ['nullable', 'email', 'max:160']]);
        $phone = $this->normalizePhone($data['phone']);
        if (! $phone) {
            return response()->json(['error' => 'Indtast et gyldigt mobilnummer'], 422);
        }
        if (! $this->activeInspectionType($request->string('inspection')->toString())) {
            return response()->json(['error' => 'Den valgte synstype kan ikke bookes online'], 422);
        }
        $startsAt = $this->requestStartsAt($request);
        if (! $startsAt || $startsAt->lte(now()->addMinutes(self::PUBLIC_MINIMUM_NOTICE_MINUTES))) {
            return response()->json(['error' => 'Vælg en tid, der ligger mindst 15 minutter fremme'], 422);
        }
        if ($startsAt->gt(CarbonImmutable::today(config('app.timezone'))->addDays(self::PUBLIC_MAXIMUM_BOOKING_DAYS)->endOfDay())) {
            return response()->json(['error' => 'Datoen ligger uden for bookingperioden'], 422);
        }
        $request->merge(['phone' => $phone, 'customerType' => 'private', 'source' => 'public_web']);

        $response = $this->createBooking($request);
        if ($response->getStatusCode() !== 201) {
            return $response;
        }

        $payload = $response->getData(true);
        $bookingId = (int) ($payload['booking']['id'] ?? 0);
        if ($bookingId < 1) {
            return $response;
        }

        $management = $this->issueBookingManagementToken($bookingId);
        $manageUrl = $this->publicFrontendUrl().$management['path'];
        $this->appendManagementLinkToSms($bookingId, $manageUrl);

        if ($request->filled('email')) {
            $booking = $this->managedBookingSummary($bookingId);
            $bookingDate = CarbonImmutable::parse($booking['date'])->format('d.m.Y');
            try {
                Mail::raw(
                    "Hej {$request->string('customer')->toString()}\n\nDin tid hos Midtjysk Bilsyn er booket til {$bookingDate} kl. {$booking['time']} for {$booking['plate']}.\n\nSe, ændr eller afbestil din tid her:\n{$manageUrl}\n\nVenlig hilsen\nMidtjysk Bilsyn",
                    fn ($mail) => $mail->to($request->string('email')->toString())->subject('Din booking hos Midtjysk Bilsyn')
                );
            } catch (\Throwable) {
            }
        }

        $payload['booking']['manageUrl'] = $management['path'];

        return response()->json($payload, 201);
    }

    public function publicManagedBooking(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'min:32', 'max:160']]);
        $token = $this->validBookingManagementToken($data['token']);
        if (! $token) {
            return response()->json(['error' => 'Linket er ugyldigt eller udløbet'], 404);
        }

        return response()->json(['booking' => $this->managedBookingSummary((int) $token->booking_id)]);
    }

    public function publicLookupBooking(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plate' => ['required', 'string', 'max:12'],
            'phone' => ['required', 'string', 'max:32'],
        ]);
        $phone = $this->normalizePhone($data['phone']);
        $plate = $this->normalizePlate($data['plate']);
        if (! $phone || $plate === '') {
            return response()->json(['error' => 'Vi kunne ikke finde en kommende booking med de oplysninger'], 404);
        }

        $booking = DB::table('bookings')
            ->join('vehicles', 'vehicles.id', '=', 'bookings.vehicle_id')
            ->join('customers', 'customers.id', '=', 'bookings.customer_id')
            ->where('vehicles.registration_normalized', $plate)
            ->where('bookings.starts_at', '>', now())
            ->whereNotIn('bookings.status', ['cancelled', 'no_show'])
            ->orderBy('bookings.starts_at')
            ->get(['bookings.id', 'customers.phone'])
            ->first(fn ($row) => hash_equals((string) $phone, (string) $this->normalizePhone($row->phone)));

        if (! $booking) {
            return response()->json(['error' => 'Vi kunne ikke finde en kommende booking med de oplysninger'], 404);
        }

        $management = $this->issueBookingManagementToken((int) $booking->id);

        return response()->json(['manageUrl' => $management['path']]);
    }

    public function publicUpdateManagedBooking(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:32', 'max:160'],
            'action' => ['required', 'in:cancel,reschedule'],
            'date' => ['required_if:action,reschedule', 'nullable', 'date_format:Y-m-d'],
            'time' => ['required_if:action,reschedule', 'nullable', 'date_format:H:i'],
        ]);
        $token = $this->validBookingManagementToken($data['token']);
        if (! $token) {
            return response()->json(['error' => 'Linket er ugyldigt eller udløbet'], 404);
        }
        $bookingId = (int) $token->booking_id;
        $current = DB::table('bookings')
            ->join('vehicles', 'vehicles.id', '=', 'bookings.vehicle_id')
            ->join('customers', 'customers.id', '=', 'bookings.customer_id')
            ->where('bookings.id', $bookingId)
            ->first(['bookings.*', 'vehicles.registration_normalized', 'customers.phone', 'customers.email', 'customers.display_name']);
        if (! $current || in_array($current->status, ['cancelled', 'no_show'], true) || CarbonImmutable::parse($current->starts_at)->lte(now()->addMinutes(self::PUBLIC_MINIMUM_NOTICE_MINUTES))) {
            return response()->json(['error' => 'Bookingen kan ikke længere ændres online'], 409);
        }

        if ($data['action'] === 'cancel') {
            DB::transaction(function () use ($bookingId, $current) {
                DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->get();
                DB::table('bookings')->where('id', $bookingId)->update(['status' => 'cancelled', 'updated_at' => now()]);
                DB::table('sms_messages')->where('booking_id', $bookingId)->whereIn('status', ['held', 'DRAFT', 'SCHEDULED', 'QUEUED'])->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'updated_at' => now()]);
                if (app(CapacityPlannerV2::class)->restoreRelocatedBuffer($bookingId)) {
                    $this->audit('capacity.buffer.restored', 'booking', $bookingId, null, ['reason' => 'booking_cancelled']);
                }
                $this->queueSms($bookingId, 'cancelled', (int) $current->customer_id, $current->phone, CarbonImmutable::parse($current->starts_at), $current->registration_normalized);
                $this->audit('booking.self_service_cancelled', 'booking', $bookingId, (array) $current, ['status' => 'cancelled']);
            });
            $this->sendBookingChangeEmail($current->email, 'Din tid er afbestilt', "Din tid hos Midtjysk Bilsyn {$this->displayDate($current->starts_at)} er nu afbestilt.");

            return response()->json(['ok' => true, 'status' => 'cancelled']);
        }

        $newStartsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['time']);
        if ($newStartsAt->lte(now()->addMinutes(15))) {
            return response()->json(['error' => 'Vælg en tid, der ligger mindst 15 minutter fremme'], 422);
        }
        if ($newStartsAt->gt(CarbonImmutable::today(config('app.timezone'))->addDays(self::PUBLIC_MAXIMUM_BOOKING_DAYS)->endOfDay())) {
            return response()->json(['error' => 'Datoen ligger uden for bookingperioden'], 422);
        }
        $result = DB::transaction(function () use ($bookingId, $current, $data, $newStartsAt) {
            $this->lockCapacityDay('ikast', $data['date']);
            DB::table('bookings')->where('id', $bookingId)->lockForUpdate()->get();
            DB::table('bookings')->whereDate('starts_at', $data['date'])->lockForUpdate()->get();
            $availability = $this->availabilityForDate($data['date'], $current->inspection_type, $bookingId, 'public');
            if (! in_array($data['time'], $availability['availableSlots'], true)) {
                return response()->json(['error' => 'Tiden er ikke længere ledig. Vælg en anden tid.'], 409);
            }
            $definition = $this->inspectionDefinition($current->inspection_type);
            DB::table('bookings')->where('id', $bookingId)->update([
                'starts_at' => $newStartsAt,
                'ends_at' => $newStartsAt->addMinutes($definition['requiredSlots'] * $availability['intervalMinutes']),
                'updated_at' => now(),
            ]);
            DB::table('sms_messages')->where('booking_id', $bookingId)->whereIn('kind', ['reminder', 'changed'])->whereIn('status', ['held', 'DRAFT', 'SCHEDULED', 'QUEUED'])->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'updated_at' => now()]);
            $this->queueSms($bookingId, 'changed', (int) $current->customer_id, $current->phone, $newStartsAt, $current->registration_normalized);
            if ($newStartsAt->isAfter(now()->addDay())) {
                $this->queueSms($bookingId, 'reminder', (int) $current->customer_id, $current->phone, $newStartsAt, $current->registration_normalized);
            }
            $this->audit('booking.self_service_rescheduled', 'booking', $bookingId, (array) $current, ['starts_at' => $newStartsAt->toDateTimeString()]);

            return null;
        });
        if ($result instanceof JsonResponse) {
            return $result;
        }
        DB::table('booking_management_tokens')->where('booking_id', $bookingId)->whereNull('revoked_at')->update(['expires_at' => $newStartsAt->addDay(), 'updated_at' => now()]);
        $manageUrl = $this->publicFrontendUrl().'/booking/manage?token='.urlencode($data['token']);
        $this->appendManagementLinkToSms($bookingId, $manageUrl);
        $this->sendBookingChangeEmail($current->email, 'Din booking er ændret', "Din tid hos Midtjysk Bilsyn er ændret til {$newStartsAt->format('d.m.Y')} kl. {$newStartsAt->format('H:i')}.\n\nSe eller afbestil din tid her:\n{$manageUrl}");

        return response()->json(['ok' => true, 'booking' => $this->managedBookingSummary($bookingId)]);
    }

    public function businessPortalLogin(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $user = DB::table('business_portal_users')->where('email', $data['email'])->where('active', true)->first();
        $active = $user ? DB::table('business_portal_settings')->where('customer_id', $user->customer_id)->where('portal_active', true)->exists() : false;
        if (! $user || ! $active || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['error' => 'Forkert e-mail eller adgangskode'], 401);
        }
        $request->session()->regenerate();
        $request->session()->put('business_portal_user_id', (int) $user->id);
        DB::table('business_portal_users')->where('id', $user->id)->update(['last_login_at' => now(), 'updated_at' => now()]);

        return $this->businessPortalSession($request);
    }

    public function businessPortalSession(Request $request): JsonResponse
    {
        $context = $this->businessPortalContext($request);
        if (! $context) {
            return response()->json(['authenticated' => false], 401);
        }

        return response()->json([
            'authenticated' => true,
            'user' => ['id' => (string) $context['user']->id, 'name' => $context['user']->name, 'email' => $context['user']->email, 'phone' => $context['user']->phone, 'role' => $context['user']->role],
            'company' => ['id' => (string) $context['customer']->id, 'name' => $context['customer']->display_name],
            'settings' => $context['settings'],
            'bookingTypes' => $this->businessPortalInspectionTypes($context['settings']),
            'permissions' => ['canManageBookings' => $context['user']->role !== 'read_only', 'canViewInvoices' => $context['user']->role === 'admin'],
        ]);
    }

    public function businessPortalForgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:160']]);
        $user = DB::table('business_portal_users')->where('email', $data['email'])->where('active', true)->first();
        if ($user) {
            $this->sendBusinessPortalPasswordReset($user);
        }

        return response()->json(['message' => self::RESET_MESSAGE]);
    }

    public function businessPortalResetPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'token' => ['required', 'string'], 'password' => ['required', 'string', 'min:6', 'confirmed']]);
        $reset = DB::table('business_portal_password_resets')->join('business_portal_users', 'business_portal_users.id', '=', 'business_portal_password_resets.user_id')->where('business_portal_users.email', $data['email'])->whereNull('business_portal_password_resets.used_at')->where('business_portal_password_resets.expires_at', '>', now())->where('business_portal_password_resets.token_hash', hash('sha256', $data['token']))->select('business_portal_password_resets.id', 'business_portal_users.id as user_id')->first();
        if (! $reset) {
            return response()->json(['error' => 'Linket er udløbet eller ugyldigt. Bed om et nyt link.'], 422);
        }
        DB::transaction(function () use ($reset, $data) {
            DB::table('business_portal_users')->where('id', $reset->user_id)->update(['password' => Hash::make($data['password']), 'updated_at' => now()]);
            DB::table('business_portal_password_resets')->where('id', $reset->id)->update(['used_at' => now()]);
        });

        return response()->json(['message' => 'Adgangskoden er ændret. Du kan nu logge ind.']);
    }

    public function businessPortalLogout(Request $request): JsonResponse
    {
        $request->session()->forget('business_portal_user_id');
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function businessPortalDashboard(Request $request): JsonResponse
    {
        $context = $this->businessPortalContext($request);
        if (! $context) {
            return response()->json(['error' => 'Log ind på branchekundeportalen'], 401);
        }
        $rows = DB::table('bookings')->leftJoin('vehicles', 'vehicles.id', '=', 'bookings.vehicle_id')
            ->where('bookings.business_customer_id', $context['customer']->id)
            ->where('bookings.starts_at', '>=', now())
            ->whereNotIn('bookings.status', ['cancelled', 'no_show'])
            ->orderBy('bookings.starts_at')->limit(200)
            ->get(['bookings.id', 'bookings.starts_at', 'bookings.inspection_type', 'bookings.status', 'bookings.requisition_number', 'bookings.contact_name', 'bookings.customer_note', 'vehicles.registration_normalized', 'vehicles.make', 'vehicles.model']);
        $bookings = $rows->map(fn ($row) => ['id' => (string) $row->id, 'date' => CarbonImmutable::parse($row->starts_at)->format('Y-m-d'), 'time' => CarbonImmutable::parse($row->starts_at)->format('H:i'), 'inspection' => $row->inspection_type, 'status' => $row->status, 'plate' => $this->formatPlate($row->registration_normalized), 'vehicle' => trim(($row->make ?? '').' '.($row->model ?? '')), 'requisitionNumber' => $row->requisition_number, 'contactName' => $row->contact_name, 'note' => $row->customer_note]);

        $response = ['bookings' => $bookings->values(), 'settings' => $context['settings']];
        if ($context['user']->role === 'admin') {
            $billing = DB::table('customer_billing_profiles')->where('customer_id', $context['customer']->id)->first();
            $invoices = DB::table('invoice_drafts')->where('customer_name', $context['customer']->display_name)->orderByDesc('period_start')->orderByDesc('id')->limit(36)->get()->map(fn ($invoice) => [
                'id' => (string) $invoice->id,
                'period' => $invoice->period,
                'status' => $invoice->status,
                'externalStatus' => $invoice->external_status,
                'amountOre' => (int) round(((float) $invoice->quantity) * ((int) $invoice->unit_price_ore)),
            ]);
            $response['billing'] = $billing;
            $response['invoices'] = $invoices->values();
        }

        return response()->json($response);
    }

    public function businessPortalAvailability(Request $request): JsonResponse
    {
        $context = $this->businessPortalContext($request);
        if (! $context) {
            return response()->json(['error' => 'Log ind på branchekundeportalen'], 401);
        }
        $data = $request->validate(['inspection' => ['required', 'string', 'max:80'], 'from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d']]);
        abort_unless($this->businessPortalInspectionType($context['settings'], $data['inspection']), 422, 'Synstypen kan ikke bookes for virksomheden');
        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->startOfDay();
        $today = CarbonImmutable::today(config('app.timezone'));
        $horizon = min(730, max(1, (int) $context['settings']->booking_horizon_days));
        abort_if($from->lt($today) || $to->lt($from) || $from->diffInDays($to) > 31 || $to->gt($today->addDays($horizon)), 422, 'Datoen ligger uden for virksomhedens bookingperiode');
        $days = [];
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $availability = $this->availabilityForDate($date->toDateString(), $data['inspection']);
            $days[] = [
                'date' => $date->toDateString(), 'availableSlots' => $availability['availableSlots'],
                'availableTimeSlots' => count($availability['availableSlots']),
                'availablePlaces' => $availability['availableCapacity'],
                'concurrentCapacity' => $availability['maxCapacity'],
                'staffOnDuty' => $availability['staffedInspectors'],
                'availableCount' => count($availability['availableSlots']),
            ];
        }

        return response()->json(['days' => $days]);
    }

    public function businessPortalCreateBooking(Request $request): JsonResponse
    {
        $context = $this->businessPortalContext($request);
        if (! $context) {
            return response()->json(['error' => 'Log ind på branchekundeportalen'], 401);
        }
        if ($context['user']->role === 'read_only') {
            return response()->json(['error' => 'Din adgang er kun læsning'], 403);
        }
        $data = $request->validate(['plate' => ['required', 'string', 'max:12'], 'vehicle' => ['nullable', 'string', 'max:200'], 'date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i'], 'inspection' => ['required', 'string', 'max:80'], 'requisitionNumber' => ['nullable', 'string', 'max:100'], 'contactName' => ['nullable', 'string', 'max:160'], 'customerNote' => ['nullable', 'string', 'max:1000']]);
        $settings = $context['settings'];
        abort_unless($this->businessPortalInspectionType($settings, $data['inspection']), 422, 'Synstypen kan ikke bookes for virksomheden');
        if ($settings->requisition_requirement === 'required' && trim((string) ($data['requisitionNumber'] ?? '')) === '') {
            return response()->json(['error' => 'Rekvisitionsnummer skal udfyldes'], 422);
        }
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['time']);
        abort_if($startsAt->lte(now()->addMinutes(self::PUBLIC_MINIMUM_NOTICE_MINUTES)), 422, 'Vælg en tid, der ligger mindst 15 minutter fremme');
        abort_if($startsAt->gt(CarbonImmutable::today(config('app.timezone'))->addDays(min(730, max(1, (int) $settings->booking_horizon_days)))->endOfDay()), 422, 'Datoen ligger uden for virksomhedens bookingperiode');
        $registration = $this->normalizePlate($data['plate']);
        abort_if(mb_strlen($registration) < 5, 422, 'Indtast et gyldigt registreringsnummer');

        return DB::transaction(function () use ($data, $context, $startsAt) {
            $this->lockCapacityDay('ikast', $data['date']);
            DB::table('availability_rules')->where('weekday', $startsAt->isoWeekday())->lockForUpdate()->get();
            DB::table('bookings')->whereDate('starts_at', $data['date'])->lockForUpdate()->get();
            $definition = $this->inspectionDefinition($data['inspection']);
            $availability = $this->availabilityForDate($data['date'], $data['inspection'], null, 'public');
            if (! in_array($data['time'], $availability['availableSlots'], true)) {
                return response()->json(['error' => 'Tidspunktet er ikke ledigt'], 409);
            }
            $registration = $this->normalizePlate($data['plate']);
            $vehicleWords = preg_split('/\s+/', trim($data['vehicle'] ?? '')) ?: [];
            $make = array_shift($vehicleWords) ?: null;
            $model = trim(implode(' ', $vehicleWords)) ?: null;
            $vehicle = DB::table('vehicles')->where('registration_normalized', $registration)->first();
            if (! $vehicle) {
                $vehicleId = DB::table('vehicles')->insertGetId(['customer_id' => $context['customer']->id, 'registration_normalized' => $registration, 'make' => $make, 'model' => $model, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $vehicleId = $vehicle->id;
                DB::table('vehicles')->where('id', $vehicleId)->update(['customer_id' => $context['customer']->id, 'make' => $make ?: $vehicle->make, 'model' => $model ?: $vehicle->model, 'updated_at' => now()]);
            }
            $departmentId = Schema::hasColumn('bookings', 'department_id') ? DB::table('departments')->where('name', 'Ikast')->value('id') : null;
            $id = DB::table('bookings')->insertGetId(['department_id' => $departmentId, 'customer_id' => $context['customer']->id, 'business_customer_id' => $context['customer']->id, 'business_portal_user_id' => $context['user']->id, 'vehicle_id' => $vehicleId, 'starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes($definition['requiredSlots'] * $availability['intervalMinutes']), 'slot_count' => $definition['requiredSlots'], 'inspection_type' => $data['inspection'], 'requisition_number' => $data['requisitionNumber'] ?? null, 'contact_name' => $data['contactName'] ?? null, 'customer_note' => $data['customerNote'] ?? null, 'status' => 'confirmed', 'source' => 'business_portal', 'booking_channel' => 'business_portal', 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('business_portal.booking.created', 'booking', $id, null, ['businessCustomerId' => $context['customer']->id, ...$data]);
            if ((bool) $context['settings']->sms_active && $context['user']->phone) {
                $this->queueSms($id, 'confirmation', (int) $context['customer']->id, $context['user']->phone, $startsAt, $registration, 'business');
                if ($startsAt->isAfter(now()->addDay())) {
                    $this->queueSms($id, 'reminder', (int) $context['customer']->id, $context['user']->phone, $startsAt, $registration, 'business');
                }
            }

            return response()->json(['booking' => ['id' => (string) $id]], 201);
        });
    }

    public function businessPortalUpdateBooking(Request $request, int $booking): JsonResponse
    {
        $context = $this->businessPortalContext($request);
        $current = $context ? DB::table('bookings')->where('id', $booking)->where('business_customer_id', $context['customer']->id)->first() : null;
        if (! $context || ! $current) {
            return response()->json(['error' => 'Bookingen findes ikke'], 404);
        }
        if ($context['user']->role === 'read_only') {
            return response()->json(['error' => 'Din adgang er kun læsning'], 403);
        }
        if (in_array($current->status, ['cancelled', 'no_show'], true)) {
            return response()->json(['error' => 'Bookingen kan ikke længere ændres'], 409);
        }
        $cutoff = CarbonImmutable::parse($current->starts_at)->subMinutes((int) $context['settings']->change_cutoff_minutes);
        if (now()->gte($cutoff)) {
            return response()->json(['error' => 'Tiden kan ikke ændres så tæt på synet'], 422);
        }
        if ($request->input('action') === 'cancel') {
            return $this->businessPortalDeleteBooking($request, $booking);
        }
        $data = $request->validate([
            'plate' => ['required', 'string', 'max:12'], 'vehicle' => ['nullable', 'string', 'max:200'],
            'date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i'],
            'inspection' => ['required', 'string', 'max:80'], 'requisitionNumber' => ['nullable', 'string', 'max:100'],
            'contactName' => ['nullable', 'string', 'max:160'], 'customerNote' => ['nullable', 'string', 'max:1000'],
        ]);
        abort_unless($this->businessPortalInspectionType($context['settings'], $data['inspection']), 422, 'Synstypen kan ikke bookes for virksomheden');
        if ($context['settings']->requisition_requirement === 'required' && trim((string) ($data['requisitionNumber'] ?? '')) === '') {
            return response()->json(['error' => 'Rekvisitionsnummer skal udfyldes'], 422);
        }
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['time']);
        abort_if($startsAt->lte(now()->addMinutes(self::PUBLIC_MINIMUM_NOTICE_MINUTES)), 422, 'Vælg en tid, der ligger mindst 15 minutter fremme');
        abort_if($startsAt->gt(CarbonImmutable::today(config('app.timezone'))->addDays(min(730, max(1, (int) $context['settings']->booking_horizon_days)))->endOfDay()), 422, 'Datoen ligger uden for virksomhedens bookingperiode');
        abort_if(mb_strlen($this->normalizePlate($data['plate'])) < 5, 422, 'Indtast et gyldigt registreringsnummer');
        $request->merge(['customer' => $context['customer']->display_name, 'customerType' => 'business', 'source' => 'business_portal', 'phone' => $context['customer']->phone ?? null]);
        $response = $this->updateBooking($request, $booking);
        if ($response->getStatusCode() < 300) {
            DB::table('bookings')->where('id', $booking)->update(['business_portal_user_id' => $context['user']->id, 'booking_channel' => 'business_portal', 'contact_name' => $data['contactName'] ?? null, 'customer_note' => $data['customerNote'] ?? null, 'updated_at' => now()]);
            $updated = DB::table('bookings')->join('vehicles', 'vehicles.id', '=', 'bookings.vehicle_id')->where('bookings.id', $booking)->first(['bookings.starts_at', 'vehicles.registration_normalized']);
            $changed = $updated && CarbonImmutable::parse($current->starts_at)->format('Y-m-d H:i') !== CarbonImmutable::parse($updated->starts_at)->format('Y-m-d H:i');
            if ($changed && (bool) $context['settings']->sms_active && $context['user']->phone) {
                DB::table('sms_messages')->where('booking_id', $booking)->whereIn('kind', ['reminder', 'changed'])->whereIn('status', ['held', 'DRAFT', 'SCHEDULED', 'QUEUED'])->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'updated_at' => now()]);
                $startsAt = CarbonImmutable::parse($updated->starts_at);
                $this->queueSms($booking, 'changed', (int) $context['customer']->id, $context['user']->phone, $startsAt, $updated->registration_normalized, 'business');
                if ($startsAt->isAfter(now()->addDay())) {
                    $this->queueSms($booking, 'reminder', (int) $context['customer']->id, $context['user']->phone, $startsAt, $updated->registration_normalized, 'business');
                }
            }
        }

        return $response;
    }

    public function businessPortalDeleteBooking(Request $request, int $booking): JsonResponse
    {
        $context = $this->businessPortalContext($request);
        $current = $context ? DB::table('bookings')->where('id', $booking)->where('business_customer_id', $context['customer']->id)->first() : null;
        if (! $context || ! $current) {
            return response()->json(['error' => 'Bookingen findes ikke'], 404);
        }
        if ($context['user']->role === 'read_only') {
            return response()->json(['error' => 'Din adgang er kun læsning'], 403);
        }
        if (in_array($current->status, ['cancelled', 'no_show'], true)) {
            return response()->json(['error' => 'Bookingen er allerede afsluttet eller aflyst'], 409);
        }
        $cutoff = CarbonImmutable::parse($current->starts_at)->subMinutes((int) $context['settings']->change_cutoff_minutes);
        if (now()->gte($cutoff)) {
            return response()->json(['error' => 'Tiden kan ikke aflyses så tæt på synet'], 422);
        }
        DB::transaction(function () use ($booking, $current, $context) {
            DB::table('bookings')->where('id', $booking)->lockForUpdate()->get();
            DB::table('bookings')->where('id', $booking)->update(['status' => 'cancelled', 'updated_at' => now()]);
            DB::table('sms_messages')->where('booking_id', $booking)->whereIn('status', ['held', 'DRAFT', 'SCHEDULED', 'QUEUED'])->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'updated_at' => now()]);
            if (app(CapacityPlannerV2::class)->restoreRelocatedBuffer($booking)) {
                $this->audit('capacity.buffer.restored', 'booking', $booking, null, ['reason' => 'booking_cancelled']);
            }
            $this->audit('business_portal.booking.cancelled', 'booking', $booking, (array) $current, ['status' => 'cancelled', 'businessCustomerId' => $context['customer']->id]);
        });

        return response()->json(['ok' => true]);
    }

    private function businessPortalContext(Request $request): ?array
    {
        $userId = $request->session()->get('business_portal_user_id');
        if (! $userId) {
            return null;
        }
        $user = DB::table('business_portal_users')->where('id', $userId)->where('active', true)->first();
        $settings = $user ? DB::table('business_portal_settings')->where('customer_id', $user->customer_id)->where('portal_active', true)->first() : null;
        $customer = $settings ? DB::table('customers')->where('id', $user->customer_id)->where('customer_type', 'business')->first() : null;
        if (! $user || ! $settings || ! $customer) {
            $request->session()->forget('business_portal_user_id');

            return null;
        }

        return compact('user', 'settings', 'customer');
    }

    public function health(): JsonResponse
    {
        $checkedAt = now()->toIso8601String();
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $error) {
            report($error);

            return response()->json([
                'status' => 'down', 'checkedAt' => $checkedAt, 'database' => 'unavailable',
                'modules' => [], 'issues' => [['module' => 'database', 'message' => 'Databasen kan ikke kontaktes']],
                'unresolvedErrors' => 1,
            ], 503);
        }

        $modules = [];
        $issues = [];
        $checks = [
            'availability' => fn () => DB::table('availability_rules')->where('kind', 'opening_hours')->exists(),
            'employees' => fn () => DB::table('employees')->limit(1)->count() >= 0 && DB::table('employee_work_rules')->limit(1)->count() >= 0,
            'businessPortal' => fn () => DB::table('business_portal_settings')->limit(1)->count() >= 0 && DB::table('business_portal_users')->limit(1)->count() >= 0,
            'invoicing' => fn () => DB::table('invoice_drafts')->limit(1)->count() >= 0 && DB::table('invoice_lines')->limit(1)->count() >= 0,
            'sms' => fn () => DB::table('sms_settings')->limit(1)->count() >= 0 && DB::table('sms_templates')->limit(1)->count() >= 0,
        ];
        foreach ($checks as $module => $check) {
            try {
                $modules[$module] = $check() ? 'ok' : 'degraded';
                if ($modules[$module] !== 'ok') {
                    $issues[] = ['module' => $module, 'message' => 'Modulet mangler nødvendig konfiguration'];
                }
            } catch (\Throwable $error) {
                report($error);
                $modules[$module] = 'down';
                $issues[] = ['module' => $module, 'message' => 'Modulet kunne ikke læse sine driftsdata'];
            }
        }
        $status = $issues === [] ? 'ok' : 'degraded';

        return response()->json([
            'status' => $status, 'checkedAt' => $checkedAt, 'database' => 'ok',
            'modules' => $modules, 'issues' => $issues, 'unresolvedErrors' => count($issues),
            'integrations' => [
                'dmr' => filled(config('services.dmr.base_url')),
                'gatewayapi' => filled(config('services.gatewayapi.base_url')),
                'dinero' => filled(config('services.dinero.base_url')),
                'synsprogram' => filled(config('services.synsprogram.base_url')),
            ],
        ], $status === 'ok' ? 200 : 503);
    }

    public function notifications(): JsonResponse
    {
        $userId = Auth::id();
        abort_unless($userId, 401);
        $this->syncSystemNotifications();

        $rows = DB::table('system_notifications as notifications')
            ->leftJoin('system_notification_user_states as states', function ($join) use ($userId) {
                $join->on('states.notification_id', '=', 'notifications.id')->where('states.user_id', '=', $userId);
            })
            ->whereNull('notifications.resolved_at')
            ->whereNull('states.dismissed_at')
            ->orderByRaw('CASE WHEN states.read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw("CASE notifications.severity WHEN 'error' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('notifications.occurred_at')
            ->limit(50)
            ->get([
                'notifications.id', 'notifications.category', 'notifications.severity', 'notifications.title',
                'notifications.message', 'notifications.action_view', 'notifications.action_label',
                'notifications.action_data', 'notifications.occurred_at', 'states.read_at',
            ])
            ->filter(fn ($row) => match ($row->action_view) {
                'invoices' => Permission::allows('invoices.read'),
                'sms' => Permission::allows('settings.write'),
                'bookings' => Permission::allows('bookings.read'),
                'drift' => Permission::allows('audit.read'),
                default => true,
            })
            ->values()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'category' => $row->category,
                'severity' => $row->severity,
                'title' => $row->title,
                'message' => $row->message,
                'actionView' => $row->action_view,
                'actionLabel' => $row->action_label,
                'actionData' => $row->action_data ? json_decode($row->action_data, true) : null,
                'occurredAt' => CarbonImmutable::parse($row->occurred_at)->toIso8601String(),
                'read' => $row->read_at !== null,
            ]);

        return response()->json(['notifications' => $rows, 'unreadCount' => $rows->where('read', false)->count()]);
    }

    public function updateNotification(Request $request, int $notification): JsonResponse
    {
        $userId = Auth::id();
        abort_unless($userId, 401);
        abort_unless(DB::table('system_notifications')->where('id', $notification)->whereNull('resolved_at')->exists(), 404);
        $action = $request->validate(['action' => ['required', 'string', 'in:read,unread,dismiss']])['action'];
        $now = now();
        $values = match ($action) {
            'read' => ['read_at' => $now, 'dismissed_at' => null],
            'unread' => ['read_at' => null, 'dismissed_at' => null],
            'dismiss' => ['read_at' => $now, 'dismissed_at' => $now],
        };
        DB::table('system_notification_user_states')->updateOrInsert(
            ['notification_id' => $notification, 'user_id' => $userId],
            [...$values, 'updated_at' => $now, 'created_at' => $now],
        );

        return response()->json(['ok' => true]);
    }

    public function readAllNotifications(): JsonResponse
    {
        $userId = Auth::id();
        abort_unless($userId, 401);
        $this->syncSystemNotifications();
        $now = now();
        $ids = DB::table('system_notifications')->whereNull('resolved_at')->pluck('id');
        foreach ($ids as $notificationId) {
            DB::table('system_notification_user_states')->updateOrInsert(
                ['notification_id' => $notificationId, 'user_id' => $userId],
                ['read_at' => $now, 'updated_at' => $now, 'created_at' => $now],
            );
        }

        return response()->json(['ok' => true]);
    }

    private function syncSystemNotifications(): void
    {
        if (! Schema::hasTable('system_notifications')) {
            return;
        }

        $activeSms = [];
        $failedMessages = DB::table('sms_messages')
            ->where(function ($query) {
                $query->whereRaw('LOWER(status) = ?', ['failed'])->orWhereNotNull('failed_at');
            })
            ->latest('id')->limit(50)->get();
        foreach ($failedMessages as $message) {
            $key = 'sms.failed.'.$message->id;
            $activeSms[] = $key;
            $recipient = $message->recipient_masked ?: 'ukendt modtager';
            $this->upsertSystemNotification($key, 'sms', 'error', 'SMS kunne ikke sendes',
                "Beskeden til {$recipient} fejlede".($message->error_message ? ': '.$message->error_message : '.'),
                'sms', 'Se SMS-status', null, $message->failed_at ?: $message->updated_at);
        }
        $this->resolveMissingNotifications('sms.failed.', $activeSms);

        $activeInvoices = [];
        foreach (DB::table('invoice_drafts')->where('requires_action', true)->latest('updated_at')->limit(50)->get() as $invoice) {
            $key = 'invoice.requires_action.'.$invoice->id;
            $activeInvoices[] = $key;
            $this->upsertSystemNotification($key, 'invoice', 'warning', 'Fakturakladde kræver handling',
                "{$invoice->customer_name} · {$invoice->period}", 'invoices', 'Åbn fakturering', null, $invoice->updated_at);
        }
        $this->resolveMissingNotifications('invoice.requires_action.', $activeInvoices);

        $activeBookings = [];
        $awaiting = DB::table('bookings')->leftJoin('customers', 'customers.id', '=', 'bookings.customer_id')
            ->join('vehicles', 'vehicles.id', '=', 'bookings.vehicle_id')
            ->where('bookings.status', 'awaiting_confirmation')->where('bookings.starts_at', '>=', now()->subDay())
            ->orderBy('bookings.starts_at')->limit(50)
            ->get(['bookings.id', 'bookings.starts_at', 'bookings.updated_at', 'customers.display_name', 'vehicles.registration_normalized']);
        foreach ($awaiting as $booking) {
            $key = 'booking.awaiting_confirmation.'.$booking->id;
            $activeBookings[] = $key;
            $date = CarbonImmutable::parse($booking->starts_at);
            $this->upsertSystemNotification($key, 'booking', 'warning', 'Booking afventer bekræftelse',
                ($booking->display_name ?: 'Ukendt kunde').' · '.$this->formatPlate($booking->registration_normalized).' · '.$date->format('d.m.Y H:i'),
                'bookings', 'Åbn booking', ['date' => $date->format('Y-m-d')], $booking->updated_at);
        }
        $this->resolveMissingNotifications('booking.awaiting_confirmation.', $activeBookings);

        $eventLabels = [
            'booking.self_service_cancelled' => ['info', 'Kunde har afbestilt sin tid', 'En privatkunde har frigivet en tid via selvbetjening.'],
            'booking.self_service_rescheduled' => ['info', 'Kunde har ændret sin tid', 'En privatkunde har flyttet sin booking via selvbetjening.'],
            'business_portal.booking.created' => ['success', 'Ny erhvervsbooking', 'En branchekunde har oprettet en booking i portalen.'],
            'business_portal.booking.cancelled' => ['info', 'Erhvervsbooking afbestilt', 'En branchekunde har afbestilt en tid i portalen.'],
        ];
        $events = DB::table('audit_events')->whereIn('action', array_keys($eventLabels))->where('created_at', '>=', now()->subDays(30))->latest()->limit(100)->get();
        foreach ($events as $event) {
            [$severity, $title, $fallback] = $eventLabels[$event->action];
            $booking = DB::table('bookings')->leftJoin('customers', 'customers.id', '=', 'bookings.customer_id')
                ->join('vehicles', 'vehicles.id', '=', 'bookings.vehicle_id')->where('bookings.id', $event->entity_id)
                ->first(['bookings.starts_at', 'customers.display_name', 'vehicles.registration_normalized']);
            $date = $booking?->starts_at ? CarbonImmutable::parse($booking->starts_at) : null;
            $message = $booking
                ? trim(($booking->display_name ?: 'Kunde').' · '.$this->formatPlate($booking->registration_normalized).($date ? ' · '.$date->format('d.m.Y H:i') : ''))
                : $fallback;
            $this->upsertSystemNotification('audit.'.$event->id, 'booking', $severity, $title, $message,
                'bookings', 'Se bookinger', $date ? ['date' => $date->format('Y-m-d')] : null, $event->created_at);
        }
        DB::table('system_notifications')->where('dedupe_key', 'like', 'audit.%')->where('occurred_at', '<', now()->subDays(30))->whereNull('resolved_at')->update(['resolved_at' => now(), 'updated_at' => now()]);
    }

    private function upsertSystemNotification(string $key, string $category, string $severity, string $title, string $message, ?string $actionView, ?string $actionLabel, ?array $actionData, mixed $occurredAt): void
    {
        DB::table('system_notifications')->updateOrInsert(['dedupe_key' => $key], [
            'category' => $category, 'severity' => $severity, 'title' => $title, 'message' => $message,
            'action_view' => $actionView, 'action_label' => $actionLabel,
            'action_data' => $actionData ? json_encode($actionData) : null,
            'occurred_at' => $occurredAt ?: now(), 'resolved_at' => null, 'updated_at' => now(), 'created_at' => now(),
        ]);
    }

    private function resolveMissingNotifications(string $prefix, array $activeKeys): void
    {
        $query = DB::table('system_notifications')->where('dedupe_key', 'like', $prefix.'%')->whereNull('resolved_at');
        if ($activeKeys !== []) {
            $query->whereNotIn('dedupe_key', $activeKeys);
        }
        $query->update(['resolved_at' => now(), 'updated_at' => now()]);
    }

    public function bookings(Request $request): JsonResponse
    {
        $date = $request->string('date')->toString() ?: now()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['error' => 'Ugyldig dato'], 400);
        }
        $rows = DB::table('bookings')->leftJoin('customers', 'customers.id', '=', 'bookings.customer_id')->join('vehicles', 'vehicles.id', '=', 'bookings.vehicle_id')
            ->whereDate('bookings.starts_at', $date)->where('bookings.status', '!=', 'cancelled')->orderBy('bookings.starts_at')
            ->get(['bookings.id', 'bookings.starts_at', 'bookings.slot_count', 'bookings.inspection_type', 'bookings.status', 'bookings.requisition_number', 'customers.display_name', 'customers.customer_type', 'customers.phone', 'vehicles.registration_normalized', 'vehicles.make', 'vehicles.model']);
        $bookings = $rows->map(fn ($row) => [
            'id' => (string) $row->id, 'date' => $date, 'time' => CarbonImmutable::parse($row->starts_at)->format('H:i'), 'slotCount' => (int) ($row->slot_count ?? 1),
            'customer' => $row->display_name ?? 'Ukendt kunde', 'customerType' => $row->customer_type ?? 'private',
            'plate' => $this->formatPlate($row->registration_normalized), 'phone' => $row->phone, 'requisitionNumber' => $row->requisition_number, 'vehicle' => trim(($row->make ?? '').' '.($row->model ?? '')),
            'inspection' => $row->inspection_type, 'status' => $row->status,
        ]);
        $availability = $this->availabilityForDate($date, $request->string('inspection')->toString() ?: null, null, 'internal');

        return response()->json([
            'bookings' => $bookings,
            'availableSlots' => $availability['availableSlots'],
            'availableTimeSlots' => count($availability['availableSlots']),
            'availablePlaces' => $availability['availableCapacity'],
            'concurrentCapacity' => $availability['maxCapacity'],
            'staffOnDuty' => $availability['staffedInspectors'],
            'slotCapacities' => $availability['slotCapacities'],
            'staffedInspectors' => $availability['staffedInspectors'],
            'capacityPlannerV2' => isset($availability['slots']) ? ['engine' => $availability['engine'], 'profiles' => $availability['profiles'], 'bufferCount' => $availability['bufferCount'], 'conflicts' => $availability['conflicts'], 'publicAvailableSlots' => $availability['publicAvailableSlots'], 'internalAvailableSlots' => $availability['internalAvailableSlots'], 'slots' => $availability['slots']] : null,
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
            $this->lockCapacityDay('ikast', $input['date']);
            DB::table('availability_rules')->where('weekday', $startsAt->isoWeekday())->lockForUpdate()->get();
            DB::table('bookings')->whereDate('starts_at', $input['date'])->lockForUpdate()->get();
            $definition = $this->inspectionDefinition($input['inspection']);
            $scope = in_array($input['source'] ?? 'manual', ['public_web', 'business_portal'], true) ? 'public' : 'internal';
            $availability = $this->availabilityForDate($input['date'], $input['inspection'], null, $scope);
            if (! in_array($input['time'], $availability['availableSlots'], true)) {
                return response()->json(['error' => $definition['requiredSlots'] > 1 ? 'Toldsynet kræver to sammenhængende ledige tider' : 'Tidspunktet er ikke åbent eller har ikke flere ledige pladser'], 409);
            }
            $selectedSlot = collect($availability['slots'] ?? [])->firstWhere('time', $input['time']);
            $usesBuffer = $scope === 'internal' && $selectedSlot && str_contains((string) ($selectedSlot['visualType'] ?? ''), 'BUFFER');
            if ($usesBuffer) {
                abort_unless(Permission::allows('capacity.buffer.use'), 403, 'Mangler rettighed til at booke i en buffertid');
                if (($input['bufferAction'] ?? null) !== 'use') {
                    return response()->json(['error' => 'Bekræft at den faste buffertid skal bruges', 'bufferRequired' => true], 422);
                }
            }

            $registration = $this->normalizePlate($input['plate']);
            $vehicleWords = preg_split('/\s+/', trim($input['vehicle'] ?? '')) ?: [];
            $make = array_shift($vehicleWords) ?: null;
            $model = trim(implode(' ', $vehicleWords)) ?: null;
            $vehicle = DB::table('vehicles')->where('registration_normalized', $registration)->first();
            if ($vehicle) {
                $customerId = $vehicle->customer_id ?: DB::table('customers')->insertGetId(['display_name' => $input['customer'], 'customer_type' => $input['customerType'], 'phone' => $input['phone'] ?? null, 'email' => $input['email'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('customers')->where('id', $customerId)->update(['display_name' => $input['customer'], 'customer_type' => $input['customerType'], 'phone' => $input['phone'] ?? null, 'email' => $input['email'] ?? null, 'updated_at' => now()]);
                DB::table('vehicles')->where('id', $vehicle->id)->update(['customer_id' => $customerId, 'make' => $make, 'model' => $model, 'updated_at' => now()]);
                $vehicleId = $vehicle->id;
            } else {
                $customerId = DB::table('customers')->insertGetId(['display_name' => $input['customer'], 'customer_type' => $input['customerType'], 'phone' => $input['phone'] ?? null, 'email' => $input['email'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
                $vehicleId = DB::table('vehicles')->insertGetId(['customer_id' => $customerId, 'registration_normalized' => $registration, 'make' => $make, 'model' => $model, 'created_at' => now(), 'updated_at' => now()]);
            }
            $departmentId = Schema::hasColumn('bookings', 'department_id') ? DB::table('departments')->where('name', 'Ikast')->value('id') : null;
            $id = DB::table('bookings')->insertGetId(['department_id' => $departmentId, 'customer_id' => $customerId, 'vehicle_id' => $vehicleId, 'starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes($definition['requiredSlots'] * $availability['intervalMinutes']), 'slot_count' => $definition['requiredSlots'], 'inspection_type' => $input['inspection'], 'requisition_number' => $input['requisitionNumber'] ?? null, 'status' => $input['status'] ?? 'confirmed', 'source' => $input['source'] ?? 'manual', 'created_at' => now(), 'updated_at' => now()]);
            if ($usesBuffer) {
                $this->audit('capacity.buffer.consumed', 'booking', $id, null, ['sourceTime' => $input['time'], 'replacement' => false]);
            }
            $this->audit('booking.created', 'booking', $id, null, $input);
            if ($input['customerType'] === 'private') {
                $this->queueSms($id, 'confirmation', $customerId, $input['phone'] ?? null, $startsAt, $registration);
                if ($startsAt->isAfter(now()->addDay())) {
                    $this->queueSms($id, 'reminder', $customerId, $input['phone'] ?? null, $startsAt, $registration);
                }
            }

            return response()->json(['booking' => ['id' => (string) $id]], 201);
        });
    }

    public function updateBooking(Request $request, int $booking): JsonResponse
    {
        $current = DB::table('bookings')->join('vehicles', 'vehicles.id', '=', 'bookings.vehicle_id')->leftJoin('customers', 'customers.id', '=', 'bookings.customer_id')->where('bookings.id', $booking)->first(['bookings.*', 'vehicles.registration_normalized', 'vehicles.make', 'vehicles.model', 'customers.display_name', 'customers.customer_type', 'customers.phone']);
        if (! $current) {
            return response()->json(['error' => 'Bookingen findes ikke'], 404);
        }
        if ($request->input('action') === 'cancel') {
            DB::transaction(function () use ($booking, $current) {
                DB::table('bookings')->where('id', $booking)->lockForUpdate()->get();
                DB::table('bookings')->where('id', $booking)->update(['status' => 'cancelled', 'updated_at' => now()]);
                DB::table('sms_messages')->where('booking_id', $booking)->whereIn('status', ['held', 'DRAFT', 'SCHEDULED', 'QUEUED'])->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'updated_at' => now()]);
                if (app(CapacityPlannerV2::class)->restoreRelocatedBuffer($booking)) {
                    $this->audit('capacity.buffer.restored', 'booking', $booking, null, ['reason' => 'booking_cancelled']);
                }
                $this->audit('booking.cancelled', 'booking', $booking, (array) $current, ['status' => 'cancelled']);
            });

            return response()->json(['ok' => true]);
        }
        $input = $this->bookingInput($request);
        if ($input instanceof JsonResponse) {
            return $input;
        }
        $result = DB::transaction(function () use ($booking, $current, $input) {
            [$make, $model] = $this->splitVehicle($input['vehicle']);
            $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $input['date'].' '.$input['time']);
            $this->lockCapacityDay('ikast', $input['date']);
            DB::table('availability_rules')->where('weekday', $startsAt->isoWeekday())->lockForUpdate()->get();
            DB::table('bookings')->whereDate('starts_at', $input['date'])->lockForUpdate()->get();
            $placementChanged = CarbonImmutable::parse($current->starts_at)->format('Y-m-d H:i') !== $startsAt->format('Y-m-d H:i') || $current->inspection_type !== $input['inspection'];
            $definition = $this->inspectionDefinition($input['inspection']);
            $scope = in_array($input['source'] ?? 'manual', ['public_web', 'business_portal'], true) ? 'public' : 'internal';
            $availability = $this->availabilityForDate($input['date'], $input['inspection'], $booking, $scope);
            if (! in_array($input['time'], $availability['availableSlots'], true)) {
                return response()->json(['error' => $definition['requiredSlots'] > 1 ? 'Toldsynet kræver to sammenhængende ledige tider' : 'Det valgte tidspunkt har ikke flere ledige pladser'], 409);
            }
            $selectedSlot = collect($availability['slots'] ?? [])->firstWhere('time', $input['time']);
            $usesBuffer = $placementChanged && $scope === 'internal' && $selectedSlot && str_contains((string) ($selectedSlot['visualType'] ?? ''), 'BUFFER');
            if ($usesBuffer) {
                abort_unless(Permission::allows('capacity.buffer.use'), 403, 'Mangler rettighed til at booke i en buffertid');
                if (($input['bufferAction'] ?? null) !== 'use') {
                    return response()->json(['error' => 'Bekræft at den faste buffertid skal bruges', 'bufferRequired' => true], 422);
                }
            }
            if ($placementChanged && app(CapacityPlannerV2::class)->restoreRelocatedBuffer($booking)) {
                $this->audit('capacity.buffer.restored', 'booking', $booking, null, ['reason' => 'booking_rescheduled']);
            }
            DB::table('customers')->where('id', $current->customer_id)->update(['display_name' => $input['customer'], 'customer_type' => $input['customerType'], 'phone' => $input['phone'] ?? null, 'updated_at' => now()]);
            DB::table('vehicles')->where('id', $current->vehicle_id)->update(['registration_normalized' => $this->normalizePlate($input['plate']), 'make' => $make, 'model' => $model, 'updated_at' => now()]);
            DB::table('bookings')->where('id', $booking)->update(['starts_at' => $startsAt, 'ends_at' => $startsAt->addMinutes($definition['requiredSlots'] * $availability['intervalMinutes']), 'slot_count' => $definition['requiredSlots'], 'inspection_type' => $input['inspection'], 'requisition_number' => $input['requisitionNumber'] ?? $current->requisition_number, 'updated_at' => now()]);
            if ($usesBuffer) {
                $this->audit('capacity.buffer.consumed', 'booking', $booking, null, ['sourceTime' => $input['time'], 'replacement' => false]);
            }
            $changed = CarbonImmutable::parse($current->starts_at)->format('Y-m-d H:i') !== $startsAt->format('Y-m-d H:i') || $current->inspection_type !== $input['inspection'] || $current->registration_normalized !== $this->normalizePlate($input['plate']);
            if ($changed && $input['customerType'] === 'private') {
                DB::table('sms_messages')->where('booking_id', $booking)->whereIn('kind', ['reminder', 'changed'])->whereIn('status', ['held', 'DRAFT', 'SCHEDULED', 'QUEUED'])->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'updated_at' => now()]);
                $this->queueSms($booking, 'changed', (int) $current->customer_id, $input['phone'] ?? $current->phone, $startsAt, $this->normalizePlate($input['plate']));
                if ($startsAt->isAfter(now()->addDay())) {
                    $this->queueSms($booking, 'reminder', (int) $current->customer_id, $input['phone'] ?? $current->phone, $startsAt, $this->normalizePlate($input['plate']));
                }
            }
            $this->audit('booking.updated', 'booking', $booking, (array) $current, $input);

            return null;
        });
        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json(['ok' => true]);
    }

    public function deleteBooking(int $booking): JsonResponse
    {
        $current = DB::table('bookings')->where('id', $booking)->first();
        if (! $current) {
            return response()->json(['error' => 'Bookingen findes ikke'], 404);
        }
        DB::transaction(function () use ($booking, $current) {
            DB::table('bookings')->where('id', $booking)->update(['status' => 'cancelled', 'updated_at' => now()]);
            DB::table('sms_messages')->where('booking_id', $booking)->whereIn('status', ['held', 'DRAFT', 'SCHEDULED', 'QUEUED'])->update(['status' => 'CANCELLED', 'cancelled_at' => now(), 'updated_at' => now()]);
            if (app(CapacityPlannerV2::class)->restoreRelocatedBuffer($booking)) {
                $this->audit('capacity.buffer.restored', 'booking', $booking, null, ['reason' => 'booking_cancelled']);
            }
            $this->audit('booking.cancelled', 'booking', $booking, (array) $current, ['status' => 'cancelled']);
        });

        return response()->json(['ok' => true]);
    }

    public function customers(): JsonResponse
    {
        $customers = DB::table('customers')->orderBy('display_name')->get()->map(function ($customer) {
            $vehicles = DB::table('vehicles')->where('customer_id', $customer->id)->get()->map(fn ($vehicle) => ['id' => (string) $vehicle->id, 'plate' => $this->formatPlate($vehicle->registration_normalized), 'vehicle' => trim(($vehicle->make ?? '').' '.($vehicle->model ?? ''))]);
            $history = DB::table('bookings')->where('customer_id', $customer->id)->latest('starts_at')->get()->map(fn ($booking) => ['id' => (string) $booking->id, 'date' => CarbonImmutable::parse($booking->starts_at)->format('Y-m-d'), 'time' => CarbonImmutable::parse($booking->starts_at)->format('H:i'), 'inspection' => $booking->inspection_type, 'status' => $booking->status]);

            return ['id' => (string) $customer->id, 'name' => $customer->display_name, 'customerType' => $customer->customer_type, 'vehicles' => $vehicles, 'history' => $history, 'billing' => DB::table('customer_billing_profiles')->where('customer_id', $customer->id)->first()];
        });

        return response()->json(['customers' => $customers]);
    }

    public function search(Request $request): JsonResponse
    {
        $term = trim($request->string('q')->toString());
        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }
        $like = '%'.$term.'%';
        $plate = $this->normalizePlate($term);
        $results = collect();
        $nowTimestamp = now()->timestamp;
        $bookingRows = DB::table('bookings')->select('bookings.id as booking_id', 'bookings.starts_at', 'bookings.inspection_type', 'bookings.status', 'bookings.requisition_number', 'customers.display_name', 'customers.customer_type', 'customers.phone', 'vehicles.registration_normalized', 'vehicles.make', 'vehicles.model')->join('customers', 'customers.id', '=', 'bookings.customer_id')->join('vehicles', 'vehicles.id', '=', 'bookings.vehicle_id')->where(function ($query) use ($like, $plate) {
            $query->where('customers.display_name', 'like', $like)->orWhere('vehicles.registration_normalized', 'like', '%'.$plate.'%')->orWhere('bookings.requisition_number', 'like', $like);
        })->where('bookings.status', '!=', 'cancelled')->limit(30)->get()->sortBy(function ($row) use ($nowTimestamp) {
            $timestamp = CarbonImmutable::parse($row->starts_at)->timestamp;

            return $timestamp >= $nowTimestamp ? $timestamp : PHP_INT_MAX + ($nowTimestamp - $timestamp);
        })->take(12)->map(fn ($row) => ['type' => 'booking', 'id' => (string) $row->booking_id, 'title' => $this->formatPlate($row->registration_normalized).' · '.$row->display_name, 'subtitle' => CarbonImmutable::parse($row->starts_at)->format('d.m.Y H:i').' · '.$row->inspection_type, 'booking' => ['id' => (string) $row->booking_id, 'date' => CarbonImmutable::parse($row->starts_at)->format('Y-m-d'), 'time' => CarbonImmutable::parse($row->starts_at)->format('H:i'), 'customer' => $row->display_name, 'customerType' => $row->customer_type, 'plate' => $this->formatPlate($row->registration_normalized), 'vehicle' => trim(($row->make ?? '').' '.($row->model ?? '')), 'inspection' => $row->inspection_type, 'status' => $row->status, 'phone' => $row->phone, 'requisitionNumber' => $row->requisition_number]]);
        $results = $results->merge($bookingRows);
        $results = $results->merge(DB::table('customers')->where('display_name', 'like', $like)->orderBy('display_name')->limit(8)->get()->map(fn ($row) => ['type' => 'customer', 'id' => (string) $row->id, 'title' => $row->display_name, 'subtitle' => $row->customer_type === 'business' ? 'Erhvervskunde' : 'Privatkunde']));
        $results = $results->merge(DB::table('vehicles')->where('registration_normalized', 'like', '%'.$plate.'%')->limit(8)->get()->map(fn ($row) => ['type' => 'vehicle', 'id' => (string) $row->id, 'title' => $this->formatPlate($row->registration_normalized), 'subtitle' => trim(($row->make ?? '').' '.($row->model ?? ''))]));

        return response()->json(['results' => $results->unique(fn ($item) => $item['type'].':'.$item['id'])->take(20)->values()]);
    }

    public function updateCustomerBilling(Request $request, int $customer): JsonResponse
    {
        abort_unless(DB::table('customers')->where('id', $customer)->exists(), 404, 'Kunden findes ikke');
        $data = $request->validate([
            'cvrNumber' => ['nullable', 'string', 'max:20'], 'address' => ['nullable', 'string', 'max:160'], 'postalCode' => ['nullable', 'string', 'max:12'], 'city' => ['nullable', 'string', 'max:100'],
            'contactName' => ['nullable', 'string', 'max:120'], 'contactEmail' => ['nullable', 'email', 'max:160'], 'invoiceEmail' => ['nullable', 'email', 'max:160'], 'invoiceCc' => ['nullable', 'email', 'max:160'],
            'billingMethod' => ['required', 'in:email,efaktura,manual,none'], 'paymentTerms' => ['required', 'in:netto_8,netto_14,netto_30,immediate'], 'eanGln' => ['nullable', 'regex:/^\d{13}$/'], 'pNumber' => ['nullable', 'string', 'max:20'],
            'requiresRequisition' => ['nullable', 'boolean'],
        ]);
        $values = collect($data)->mapWithKeys(fn ($value, $key) => [Str::snake($key) => $value])->all();
        DB::table('customer_billing_profiles')->updateOrInsert(['customer_id' => $customer], $values + ['created_at' => now(), 'updated_at' => now()]);
        $this->audit('customer.billing.updated', 'customer', $customer, null, $data);

        return response()->json(['billing' => DB::table('customer_billing_profiles')->where('customer_id', $customer)->first()]);
    }

    public function vehicleLookup(Request $request, DmrLookupService $dmr): JsonResponse
    {
        $registration = $request->string('plate')->toString() ?: $request->string('registration')->toString();
        if ($this->normalizePlate($registration) === '') {
            return response()->json(['found' => false, 'dmr' => ['enabled' => filled(config('services.dmr.base_url')), 'status' => 'not_checked']], 400);
        }
        $result = $dmr->lookup($registration);
        if (! ($result['unavailable'] ?? false)) {
            $vehicle = $result['vehicle'] ?? [];
            $result['lastInspectionDate'] ??= $vehicle['inspectionDate'] ?? null;
            $result['inspectionDueDate'] ??= $vehicle['nextInspectionDate'] ?? null;
            $result['dmr'] = ['enabled' => true, 'status' => 'connected'];

            return response()->json($result);
        }
        $vehicle = DB::table('vehicles')->where('registration_normalized', $this->normalizePlate($registration))->first();
        if (! $vehicle) {
            return response()->json(['found' => false, 'source' => 'local-mysql', 'unavailable' => true, 'dmr' => ['enabled' => filled(config('services.dmr.base_url')), 'status' => 'unavailable']]);
        }

        return response()->json(['found' => true, 'source' => 'local-mysql', 'vehicle' => ['registration' => $this->formatPlate($vehicle->registration_normalized), 'make' => $vehicle->make, 'model' => $vehicle->model], 'unavailable' => true, 'dmr' => ['enabled' => filled(config('services.dmr.base_url')), 'status' => 'unavailable']]);
    }

    public function publicVehicleLookup(Request $request, DmrLookupService $dmr): JsonResponse
    {
        $registration = $request->string('plate')->toString();
        if ($this->normalizePlate($registration) === '') {
            return response()->json(['found' => false, 'status' => 'not_checked'], 400);
        }

        $result = $dmr->lookup($registration);
        if (($result['unavailable'] ?? false) || ! ($result['found'] ?? false)) {
            return response()->json(['found' => false, 'status' => ($result['unavailable'] ?? false) ? 'unavailable' : 'connected']);
        }

        $vehicle = $result['vehicle'] ?? [];

        return response()->json([
            'found' => true,
            'status' => 'connected',
            'vehicle' => [
                'registration' => $vehicle['registration'] ?? $this->formatPlate($this->normalizePlate($registration)),
                'make' => $vehicle['make'] ?? null,
                'model' => $vehicle['model'] ?? null,
                'inspectionDate' => $vehicle['inspectionDate'] ?? null,
                'nextInspectionDate' => $vehicle['nextInspectionDate'] ?? null,
            ],
        ]);
    }

    public function availability(): JsonResponse
    {
        return response()->json(['rules' => DB::table('availability_rules')->orderBy('weekday')->orderBy('date_from')->get()]);
    }

    public function holidaySuggestions(Request $request, DanishHolidayService $holidays): JsonResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'between:2020,2100']]);

        return response()->json([
            'year' => (int) $data['year'],
            'holidays' => $this->decorateHolidaySuggestions($holidays->forYear((int) $data['year'])),
        ]);
    }

    public function applyHolidaySuggestions(Request $request, DanishHolidayService $holidays): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2020,2100'],
            'dates' => ['required', 'array', 'min:1', 'max:10'],
            'dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
        ]);
        $canonical = collect($holidays->forYear((int) $data['year']))->keyBy('date');
        $requestedDates = collect($data['dates']);
        if ($requestedDates->contains(fn (string $date) => ! $canonical->has($date))) {
            return response()->json(['error' => 'Listen indeholder en dato, som ikke er en dansk helligdag i det valgte år'], 422);
        }

        $result = DB::transaction(function () use ($canonical, $requestedDates) {
            $rules = DB::table('availability_rules')->lockForUpdate()->get();
            $created = [];
            $skipped = [];
            $today = now('Europe/Copenhagen')->toDateString();

            foreach ($requestedDates as $dateString) {
                $holiday = $canonical->get($dateString);
                $date = CarbonImmutable::parse($dateString);
                $periodClosure = $rules->first(fn ($rule) => in_array($rule->kind, ['holiday', 'vacation'], true)
                    && $rule->date_from && $rule->date_to
                    && (string) $rule->date_from <= $dateString && (string) $rule->date_to >= $dateString);
                $weekdayRules = $rules->where('weekday', $date->isoWeekday());
                $weeklyClosed = ! $weekdayRules->contains('kind', 'opening_hours') || $weekdayRules->contains('kind', 'closed_day');

                if ($dateString < $today || $periodClosure || $weeklyClosed) {
                    $skipped[] = $dateString;

                    continue;
                }

                $id = DB::table('availability_rules')->insertGetId([
                    'kind' => 'holiday',
                    'date_from' => $dateString,
                    'date_to' => $dateString,
                    'label' => $holiday['name'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $rules->push((object) ['id' => $id, 'kind' => 'holiday', 'weekday' => null, 'date_from' => $dateString, 'date_to' => $dateString, 'label' => $holiday['name']]);
                $this->audit('closure.created', 'availability', $id, null, [
                    'kind' => 'holiday', 'dateFrom' => $dateString, 'dateTo' => $dateString,
                    'label' => $holiday['name'], 'source' => 'danish-holiday-suggestion',
                ]);
                $created[] = $dateString;
            }

            return ['created' => $created, 'skipped' => $skipped];
        });

        return response()->json($result + ['createdCount' => count($result['created']), 'skippedCount' => count($result['skipped'])]);
    }

    public function updateAvailability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'weekday' => ['required', 'integer', 'between:1,7'], 'closed' => ['nullable', 'boolean'],
            'startsAt' => ['nullable', 'date_format:H:i'], 'endsAt' => ['nullable', 'date_format:H:i'],
            'breaks' => ['nullable', 'array', 'max:8'], 'breaks.*.startsAt' => ['required_with:breaks', 'date_format:H:i'],
            'breaks.*.endsAt' => ['required_with:breaks', 'date_format:H:i'],
            // Beholdes under overgangen for ældre klienter.
            'breakStartsAt' => ['nullable', 'date_format:H:i'], 'breakEndsAt' => ['nullable', 'date_format:H:i'],
        ]);
        $breaks = collect($data['breaks'] ?? [])->map(fn ($pause) => ['startsAt' => $pause['startsAt'], 'endsAt' => $pause['endsAt']]);
        if ($breaks->isEmpty() && ! empty($data['breakStartsAt']) && ! empty($data['breakEndsAt'])) {
            $breaks->push(['startsAt' => $data['breakStartsAt'], 'endsAt' => $data['breakEndsAt']]);
        }
        if (! ($data['closed'] ?? false)) {
            if (empty($data['startsAt']) || empty($data['endsAt']) || $data['startsAt'] >= $data['endsAt']) {
                return response()->json(['error' => 'Åbningstidens start skal ligge før sluttiden'], 422);
            }
            foreach ($breaks as $pause) {
                if ($pause['startsAt'] >= $pause['endsAt'] || $pause['startsAt'] < $data['startsAt'] || $pause['endsAt'] > $data['endsAt']) {
                    return response()->json(['error' => 'Alle pauser skal starte før de slutter og ligge inden for åbningstiden'], 422);
                }
            }
            $orderedBreaks = $breaks->sortBy('startsAt')->values();
            foreach ($orderedBreaks as $index => $pause) {
                if ($index > 0 && $orderedBreaks[$index - 1]['endsAt'] > $pause['startsAt']) {
                    return response()->json(['error' => 'To pauser må ikke overlappe hinanden'], 422);
                }
            }
        }
        DB::transaction(function () use ($data, $breaks) {
            DB::table('availability_rules')->where('weekday', $data['weekday'])->whereIn('kind', ['opening_hours', 'break', 'closed_day'])->delete();
            if ($data['closed'] ?? false) {
                DB::table('availability_rules')->insert(['kind' => 'closed_day', 'weekday' => $data['weekday'], 'label' => 'Fast lukkedag', 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('availability_rules')->insert(['kind' => 'opening_hours', 'weekday' => $data['weekday'], 'starts_at' => $data['startsAt'], 'ends_at' => $data['endsAt'], 'label' => 'Normal åbningstid', 'created_at' => now(), 'updated_at' => now()]);
                foreach ($breaks->sortBy('startsAt') as $pause) {
                    DB::table('availability_rules')->insert(['kind' => 'break', 'weekday' => $data['weekday'], 'starts_at' => $pause['startsAt'], 'ends_at' => $pause['endsAt'], 'label' => 'Pause', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $this->audit('availability.updated', 'availability', 'weekday-'.$data['weekday'], null, $data + ['breaks' => $breaks->values()->all()]);
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
                return ['date' => $date->toDateString(), 'weekday' => $date->isoWeekday(), 'closed' => true, 'timeSlots' => 0, 'totalPlaces' => 0, 'bookedPlaces' => 0, 'availablePlaces' => 0, 'concurrentCapacity' => 0, 'staffOnDuty' => 0, 'totalSlots' => 0, 'bookedSlots' => 0, 'availableSlots' => [], 'bufferCount' => 0];
            }
            $availability = $this->availabilityForDate($date->toDateString());

            return [
                'date' => $date->toDateString(), 'weekday' => $date->isoWeekday(), 'closed' => false,
                'timeSlots' => count($availability['slotCapacities']),
                'totalPlaces' => $availability['totalCapacity'],
                'bookedPlaces' => $availability['bookedSlots'],
                'availablePlaces' => $availability['availableCapacity'],
                'concurrentCapacity' => $availability['maxCapacity'],
                'staffOnDuty' => $availability['staffedInspectors'],
                'totalSlots' => $availability['totalCapacity'],
                'bookedSlots' => $availability['bookedSlots'],
                'availableCapacity' => $availability['availableCapacity'],
                'availableSlots' => $availability['availableSlots'],
                'bufferCount' => $availability['bufferCount'] ?? 0,
                'staffedInspectors' => $availability['staffedInspectors'],
            ];
        });

        return response()->json(['week' => $startDate->isoWeek(), 'start' => $start, 'end' => $startDate->addDays(6)->toDateString(), 'days' => $days]);
    }

    public function employees(): JsonResponse
    {
        $permissionRows = DB::table('employee_permissions')->get()->groupBy('employee_id');
        $departments = Schema::hasTable('departments') ? DB::table('departments')->where('active', true)->orderBy('name')->get(['id', 'name']) : collect();
        $employees = DB::table('employees')->orderBy('display_name')->get()->map(function ($employee) use ($permissionRows) {
            $rows = $permissionRows->get($employee->id, collect());
            $allowed = $employee->role === 'Teknisk ansvarlig / Ejer'
                ? array_keys(Permission::catalog())
                : ($rows->isNotEmpty() ? $rows->where('allowed', true)->pluck('permission_key')->values()->all() : Permission::rolePermissions((string) $employee->role));

            $userColumns = ['email'];
            if (Schema::hasColumn('users', 'last_login_at')) {
                $userColumns[] = 'last_login_at';
            }
            $user = $employee->user_id ? DB::table('users')->where('id', $employee->user_id)->first($userColumns) : null;

            return [
                'id' => (string) $employee->id,
                'name' => $employee->display_name,
                'initials' => $employee->initials ?: $this->employeeInitials($employee->display_name),
                'employeeNumber' => $employee->employee_number,
                'role' => $employee->role,
                'jobTitle' => $employee->job_title ?: $employee->role,
                'status' => $employee->status ?: ($employee->active ? 'ACTIVE' : 'INACTIVE'),
                'active' => (bool) $employee->active,
                'archived' => (bool) ($employee->archived ?? false),
                'startDate' => $employee->start_date,
                'endDate' => $employee->end_date,
                'email' => $employee->email ?: $user?->email,
                'loginStatus' => $user ? ($employee->active ? 'ACTIVE' : 'DISABLED') : 'NONE',
                'lastLoginAt' => $user?->last_login_at ?? null,
                'bookingCapacity' => (bool) $employee->booking_capacity,
                'permissions' => $allowed,
                'departments' => Schema::hasTable('employee_departments') ? DB::table('employee_departments')->join('departments', 'departments.id', '=', 'employee_departments.department_id')->where('employee_departments.employee_id', $employee->id)->where('employee_departments.active_to', null)->pluck('departments.name')->values()->all() : [],
            ];
        });

        $today = CarbonImmutable::today();
        $week = collect(range(0, 6))->map(function ($offset) use ($today) {
            $date = $today->startOfWeek()->addDays($offset);
            $availability = $this->availabilityForDate($date->toDateString());

            return [
                'date' => $date->toDateString(),
                'weekday' => $date->isoWeekday(),
                'staffOnDuty' => $availability['staffedInspectors'],
                'concurrentCapacity' => $availability['maxCapacity'],
            ];
        })->values();

        return response()->json([
            'permissionCatalog' => Permission::catalog(), 'employees' => $employees, 'departments' => $departments,
            'absences' => DB::table('employee_absences')->orderBy('date_from')->get(),
            'workRules' => DB::table('employee_work_rules')->orderBy('employee_id')->orderBy('weekday')->get(),
            'capacitySummary' => ['today' => $week->firstWhere('date', $today->toDateString()), 'week' => $week],
        ]);
    }

    public function businessPortalCompanies(): JsonResponse
    {
        $settings = DB::table('business_portal_settings')->get()->keyBy('customer_id');
        $users = DB::table('business_portal_users')->orderBy('name')->get(['id', 'customer_id', 'name', 'email', 'phone', 'role', 'active', 'last_login_at'])->groupBy('customer_id');
        $companies = DB::table('customers')->where('customer_type', 'business')->orderBy('display_name')->get()->map(function ($customer) use ($settings, $users) {
            $setting = $settings->get($customer->id);
            $companyUsers = $users->get($customer->id, collect())->map(fn ($user) => ['id' => (string) $user->id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'role' => $user->role, 'active' => (bool) $user->active, 'lastLoginAt' => $user->last_login_at]);

            return ['id' => (string) $customer->id, 'name' => $customer->display_name, 'portalActive' => (bool) ($setting?->portal_active ?? false), 'smsActive' => (bool) ($setting?->sms_active ?? false), 'customerNumber' => $setting?->customer_number, 'defaultDepartment' => $setting?->default_department, 'allowedDepartments' => $setting?->allowed_departments ? json_decode($setting->allowed_departments, true) : [], 'allowedInspectionTypes' => $setting?->allowed_inspection_types ? json_decode($setting->allowed_inspection_types, true) : [], 'requisitionRequirement' => $setting?->requisition_requirement ?? 'optional', 'changeCutoffMinutes' => (int) ($setting?->change_cutoff_minutes ?? 120), 'bookingHorizonDays' => (int) ($setting?->booking_horizon_days ?? 90), 'activeUsers' => $companyUsers->where('active', true)->count(), 'users' => $companyUsers->values()];
        });

        return response()->json(['companies' => $companies]);
    }

    public function updateBusinessPortal(Request $request): JsonResponse
    {
        $type = $request->string('type')->toString();
        if ($type === 'company') {
            $data = $request->validate(['customerId' => ['required', 'integer', 'exists:customers,id'], 'portalActive' => ['required', 'boolean'], 'smsActive' => ['required', 'boolean'], 'customerNumber' => ['nullable', 'string', 'max:40'], 'defaultDepartment' => ['nullable', 'string', 'max:120'], 'allowedDepartments' => ['nullable', 'array'], 'allowedInspectionTypes' => ['nullable', 'array'], 'requisitionRequirement' => ['required', 'in:hidden,optional,required'], 'changeCutoffMinutes' => ['required', 'integer', 'min:0', 'max:10080'], 'bookingHorizonDays' => ['required', 'integer', 'min:1', 'max:730']]);
            abort_unless(DB::table('customers')->where('id', $data['customerId'])->where('customer_type', 'business')->exists(), 422, 'Kun erhvervskunder kan få portaladgang');
            DB::table('business_portal_settings')->updateOrInsert(['customer_id' => $data['customerId']], ['customer_number' => $data['customerNumber'] ?? null, 'default_department' => $data['defaultDepartment'] ?? null, 'allowed_departments' => json_encode(array_values($data['allowedDepartments'] ?? [])), 'allowed_inspection_types' => json_encode(array_values($data['allowedInspectionTypes'] ?? [])), 'portal_active' => $data['portalActive'], 'sms_active' => $data['smsActive'], 'requisition_requirement' => $data['requisitionRequirement'], 'change_cutoff_minutes' => $data['changeCutoffMinutes'], 'booking_horizon_days' => $data['bookingHorizonDays'], 'updated_at' => now(), 'created_at' => now()]);
            $this->audit('business_portal.company.updated', 'customer', $data['customerId'], null, $data);

            return response()->json(['ok' => true]);
        }
        if ($type === 'user_password_reset') {
            $data = $request->validate(['type' => ['required', 'in:user_password_reset'], 'customerId' => ['required', 'integer', 'exists:customers,id'], 'userId' => ['required', 'integer', 'exists:business_portal_users,id']]);
            $user = DB::table('business_portal_users')->where('id', $data['userId'])->where('customer_id', $data['customerId'])->where('active', true)->first();
            abort_unless($user, 404, 'Portalbrugeren findes ikke');
            $this->sendBusinessPortalPasswordReset($user);
            $this->audit('business_portal.user.password_reset_requested', 'business_portal_user', $user->id, null, ['email' => $user->email]);

            return response()->json(['message' => 'Nulstillingslinket er sendt til '.$user->email]);
        }
        $data = $request->validate(['type' => ['required', 'in:user'], 'customerId' => ['required', 'integer', 'exists:customers,id'], 'name' => ['required', 'string', 'max:160'], 'email' => ['required', 'email', 'max:255', 'unique:business_portal_users,email'], 'phone' => ['nullable', 'string', 'max:32'], 'password' => ['required', 'string', 'min:6'], 'role' => ['required', 'in:admin,employee,read_only']]);
        abort_unless(DB::table('customers')->where('id', $data['customerId'])->where('customer_type', 'business')->exists(), 422, 'Kun erhvervskunder kan få portalbrugere');
        $id = DB::table('business_portal_users')->insertGetId(['customer_id' => $data['customerId'], 'name' => $data['name'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null, 'password' => Hash::make($data['password']), 'role' => $data['role'], 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('business_portal.user.created', 'business_portal_user', $id, null, ['customerId' => $data['customerId'], 'email' => $data['email'], 'role' => $data['role']]);

        return response()->json(['id' => (string) $id], 201);
    }

    public function deleteBusinessPortalUser(int $portalUser): JsonResponse
    {
        $user = DB::table('business_portal_users')->where('id', $portalUser)->first();
        if (! $user) {
            return response()->json(['error' => 'Portalbrugeren findes ikke'], 404);
        }
        if ($user->role === 'admin') {
            $otherAdmins = DB::table('business_portal_users')->where('customer_id', $user->customer_id)->where('role', 'admin')->where('active', true)->where('id', '!=', $portalUser)->count();
            if ($otherAdmins < 1) {
                return response()->json(['error' => 'Opret en anden administrator, før den sidste administrator slettes'], 409);
            }
        }

        DB::transaction(function () use ($portalUser, $user) {
            DB::table('business_portal_password_resets')->where('user_id', $portalUser)->delete();
            DB::table('business_portal_users')->where('id', $portalUser)->delete();
            $this->audit('business_portal.user.deleted', 'business_portal_user', $portalUser, (array) $user, null);
        });

        return response()->json(['ok' => true]);
    }

    public function updateEmployee(Request $request): JsonResponse
    {
        $type = $request->string('type')->toString();
        $requiredPermission = match ($type) {
            'work_rule' => 'employees.schedule.write',
            'employee_permissions' => 'employees.permissions.write',
            'absence' => 'employees.absence.write',
            default => 'employees.write',
        };
        abort_unless(Permission::allows($requiredPermission), 403, 'Mangler rettighed: '.$requiredPermission);
        if ($type === 'employee_create') {
            $data = $request->validate([
                'displayName' => ['required', 'string', 'max:160'],
                'initials' => ['nullable', 'string', 'max:8'],
                'employeeNumber' => ['nullable', 'string', 'max:40', 'unique:employees,employee_number'],
                'email' => ['nullable', 'email', 'max:255', 'unique:employees,email'],
                'role' => ['required', 'string', 'max:80'],
                'jobTitle' => ['nullable', 'string', 'max:160'],
                'status' => ['required', 'in:ACTIVE,UPCOMING,INACTIVE,TERMINATED,ARCHIVED'],
                'startDate' => ['nullable', 'date'],
                'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
                'bookingCapacity' => ['required', 'boolean'],
                'departmentId' => ['nullable', 'integer', 'exists:departments,id'],
            ]);
            $id = DB::transaction(function () use ($data) {
                $id = DB::table('employees')->insertGetId([
                    'display_name' => trim($data['displayName']),
                    'initials' => $data['initials'] ?: $this->employeeInitials($data['displayName']),
                    'employee_number' => $data['employeeNumber'] ?? null,
                    'email' => $data['email'] ?? null,
                    'role' => $data['role'],
                    'job_title' => $data['jobTitle'] ?? $data['role'],
                    'status' => $data['status'],
                    'active' => in_array($data['status'], ['ACTIVE', 'UPCOMING'], true),
                    'start_date' => $data['startDate'] ?? null,
                    'end_date' => $data['endDate'] ?? null,
                    'booking_capacity' => $data['bookingCapacity'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                if (! empty($data['departmentId']) && Schema::hasTable('employee_departments')) {
                    DB::table('employee_departments')->insert(['employee_id' => $id, 'department_id' => $data['departmentId'], 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
                $this->audit('employee.created', 'employee', $id, null, $data);

                return $id;
            });

            return response()->json(['id' => (string) $id], 201);
        }
        if ($type === 'employee_update') {
            $data = $request->validate(['employeeId' => ['required', 'integer', 'exists:employees,id'], 'displayName' => ['required', 'string', 'max:160'], 'initials' => ['nullable', 'string', 'max:8'], 'role' => ['required', 'string', 'max:80'], 'jobTitle' => ['nullable', 'string', 'max:160'], 'status' => ['nullable', 'in:ACTIVE,UPCOMING,INACTIVE,TERMINATED,ARCHIVED'], 'active' => ['required', 'boolean'], 'bookingCapacity' => ['required', 'boolean'], 'startDate' => ['nullable', 'date'], 'endDate' => ['nullable', 'date', 'after_or_equal:startDate']]);
            DB::table('employees')->where('id', $data['employeeId'])->update(['display_name' => trim($data['displayName']), 'initials' => $data['initials'] ?: $this->employeeInitials($data['displayName']), 'role' => $data['role'], 'job_title' => $data['jobTitle'] ?? $data['role'], 'status' => $data['status'] ?? ($data['active'] ? 'ACTIVE' : 'INACTIVE'), 'active' => $data['active'], 'booking_capacity' => $data['bookingCapacity'], 'start_date' => $data['startDate'] ?? null, 'end_date' => $data['endDate'] ?? null, 'updated_at' => now()]);
            $this->audit('employee.updated', 'employee', $data['employeeId'], null, $data);

            return response()->json(['ok' => true]);
        }
        if ($type === 'work_rule') {
            $data = $request->validate(['employeeId' => ['required', 'integer', 'exists:employees,id'], 'weekday' => ['required', 'integer', 'between:1,7'], 'startsAt' => ['nullable', 'date_format:H:i'], 'endsAt' => ['nullable', 'date_format:H:i'], 'working' => ['required', 'boolean'], 'cycleWeeks' => ['nullable', 'integer', 'between:1,3'], 'cycleWeek' => ['nullable', 'integer', 'between:1,3'], 'anchorMondayDate' => ['nullable', 'date'], 'validFrom' => ['nullable', 'date'], 'validTo' => ['nullable', 'date', 'after_or_equal:validFrom']]);
            $cycleWeeks = (int) ($data['cycleWeeks'] ?? 1);
            $cycleWeek = (int) ($data['cycleWeek'] ?? 1);
            abort_if($cycleWeek > $cycleWeeks, 422, 'Uge i rul skal være inden for rullets længde');
            DB::table('employee_work_rules')->updateOrInsert(['employee_id' => $data['employeeId'], 'weekday' => $data['weekday']], ['starts_at' => $data['startsAt'] ?? null, 'ends_at' => $data['endsAt'] ?? null, 'working' => $data['working'], 'cycle_weeks' => $cycleWeeks, 'cycle_week' => $cycleWeek, 'anchor_monday_date' => $data['anchorMondayDate'] ?? null, 'valid_from' => $data['validFrom'] ?? null, 'valid_to' => $data['validTo'] ?? null, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('employee.work_rule.updated', 'employee', $data['employeeId'], null, $data);

            return response()->json(['ok' => true]);
        }
        if ($type === 'employee_permissions') {
            $data = $request->validate(['employeeId' => ['required', 'integer', 'exists:employees,id'], 'permissions' => ['required', 'array']]);
            $catalog = Permission::catalog();
            $permissions = collect($data['permissions'])->filter(fn ($allowed, $key) => array_key_exists($key, $catalog))->map(fn ($allowed) => (bool) $allowed);
            DB::transaction(function () use ($data, $permissions, $catalog) {
                DB::table('employee_permissions')->where('employee_id', $data['employeeId'])->delete();
                $now = now();
                $rows = collect($catalog)->keys()->map(fn ($key) => ['employee_id' => $data['employeeId'], 'permission_key' => $key, 'allowed' => $permissions->get($key, false), 'created_at' => $now, 'updated_at' => $now])->all();
                DB::table('employee_permissions')->insert($rows);
            });
            $this->audit('employee.permissions.updated', 'employee', $data['employeeId'], null, ['permissions' => $permissions->filter()->keys()->values()->all()]);

            return response()->json(['ok' => true]);
        }
        abort_unless($type === 'absence', 422, 'Ukendt medarbejderhandling');
        $data = $request->validate(['employeeId' => ['required', 'integer', 'exists:employees,id'], 'kind' => ['required', 'string', 'max:32'], 'dateFrom' => ['required', 'date'], 'dateTo' => ['required', 'date', 'after_or_equal:dateFrom'], 'note' => ['nullable', 'string', 'max:255']]);
        $id = DB::table('employee_absences')->insertGetId(['employee_id' => $data['employeeId'], 'kind' => $data['kind'], 'date_from' => $data['dateFrom'], 'date_to' => $data['dateTo'], 'note' => $data['note'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('employee.absence.created', 'employee_absence', $id, null, $data);

        return response()->json(['id' => (string) $id], 201);
    }

    public function planning(Request $request): JsonResponse
    {
        $date = $request->string('date')->toString() ?: now()->toDateString();
        $v2Plan = config('capacity_planner.enabled') && Schema::hasTable('capacity_profiles_v2')
            ? app(CapacityPlannerV2::class)->buildDayPlan($request->string('location')->toString() ?: 'ikast', $date)
            : null;

        return response()->json([
            'inspectionTypes' => DB::table('inspection_types')->orderBy('sort_order')->get(),
            'profiles' => DB::table('calendar_profiles')->where('is_active', true)->orderBy('name')->get(),
            'profileBuffers' => DB::table('profile_buffer_rules')->where('is_active', true)->orderBy('weekday')->orderBy('starts_at')->get(),
            'buffers' => DB::table('buffer_slots')->whereDate('date', $date)->orderBy('starts_at')->get(),
            'day' => $this->planningSummary($date),
            'capacityPlannerV2' => ['enabled' => (bool) config('capacity_planner.enabled'), 'plan' => $v2Plan, 'profiles' => Schema::hasTable('capacity_profiles_v2') ? DB::table('capacity_profiles_v2')->where('active', true)->where('location_slug', $request->string('location')->toString() ?: 'ikast')->orderByDesc('priority')->get()->unique('staffing_level')->sortBy('staffing_level')->values() : [], 'recurringBuffers' => Schema::hasTable('recurring_buffer_rules') ? DB::table('recurring_buffer_rules')->where('active', true)->where('location_slug', $request->string('location')->toString() ?: 'ikast')->orderBy('start_local_time')->get() : [], 'overrides' => Schema::hasTable('schedule_overrides') ? DB::table('schedule_overrides')->where('location_slug', $request->string('location')->toString() ?: 'ikast')->whereDate('starts_at', $date)->orderBy('starts_at')->get() : []],
        ]);
    }

    public function capacityDayPlan(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'location' => ['nullable', 'string', 'max:80'], 'inspection' => ['nullable', 'string', 'max:100'], 'view' => ['nullable', 'in:internal,public']]);
        $plan = app(CapacityPlannerV2::class)->buildDayPlan($data['location'] ?? 'ikast', $data['date'], $data['inspection'] ?? null);
        if (($data['view'] ?? 'internal') === 'public') {
            $plan['slots'] = collect($plan['slots'])->map(fn ($slot) => ['startsAt' => $slot['startsAt'], 'endsAt' => $slot['endsAt'], 'time' => $slot['time'], 'publicState' => $slot['publicState']])->values()->all();
            unset($plan['internalAvailableSlots'], $plan['buffers'], $plan['conflicts']);
        }

        return response()->json($plan);
    }

    public function updateCapacityProfileV2(Request $request, int $profile): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'staffingLevel' => ['required', 'in:ZERO,ONE,TWO_PLUS,CUSTOM'],
            'publicCapacityPerStart' => ['required', 'integer', 'between:0,10'], 'autoBufferEnabled' => ['required', 'boolean'],
            'openSlotsPerCycle' => ['required', 'integer', 'between:0,12'], 'bufferSlotsPerCycle' => ['required', 'integer', 'between:0,12'],
            'bufferInternalBookable' => ['required', 'boolean'], 'suggestBufferMove' => ['nullable', 'boolean'],
            'bufferMoveDirection' => ['nullable', 'in:LATER_FIRST,EARLIER_FIRST'], 'maxBufferMoveMinutes' => ['nullable', 'integer', 'between:20,720'],
            'resetPatternAfterClosure' => ['nullable', 'boolean'], 'weekdays' => ['nullable', 'array'], 'startsAt' => ['nullable', 'date_format:H:i'], 'endsAt' => ['nullable', 'date_format:H:i'],
        ]);
        abort_if($data['autoBufferEnabled'] && ($data['openSlotsPerCycle'] + $data['bufferSlotsPerCycle'] === 0), 422, 'Buffermønstret skal indeholde mindst én blok');
        $current = DB::table('capacity_profiles_v2')->where('id', $profile)->first();
        abort_unless($current, 404, 'Kapacitetsprofilen findes ikke');
        $values = ['name' => $data['name'], 'staffing_level' => $data['staffingLevel'], 'public_capacity_per_start' => $data['publicCapacityPerStart'], 'auto_buffer_enabled' => $data['autoBufferEnabled'], 'open_slots_per_cycle' => $data['openSlotsPerCycle'], 'buffer_slots_per_cycle' => $data['bufferSlotsPerCycle'], 'buffer_internal_bookable' => $data['bufferInternalBookable'], 'suggest_buffer_move' => false, 'buffer_move_direction' => $data['bufferMoveDirection'] ?? 'LATER_FIRST', 'max_buffer_move_minutes' => $data['maxBufferMoveMinutes'] ?? 180, 'reset_pattern_after_closure' => false, 'weekdays' => isset($data['weekdays']) ? json_encode(array_values($data['weekdays'])) : null, 'starts_at' => $data['startsAt'] ?? null, 'ends_at' => $data['endsAt'] ?? null, 'updated_at' => now()];
        DB::table('capacity_profiles_v2')->where('id', $profile)->update($values);
        $this->audit('capacity.profile.updated', 'capacity_profile_v2', $profile, (array) $current, $values);

        return response()->json(['profile' => DB::table('capacity_profiles_v2')->where('id', $profile)->first()]);
    }

    public function createRecurringBufferV2(Request $request): JsonResponse
    {
        $data = $request->validate(['location' => ['nullable', 'string', 'max:80'], 'name' => ['required', 'string', 'max:160'], 'weekdays' => ['required', 'array', 'min:1'], 'weekdays.*' => ['integer', 'between:1,7'], 'startLocalTime' => ['required', 'date_format:H:i'], 'durationMinutes' => ['required', 'integer', 'min:20', 'max:240'], 'validFrom' => ['nullable', 'date'], 'validTo' => ['nullable', 'date', 'after_or_equal:validFrom'], 'internalBookable' => ['required', 'boolean']]);
        $id = DB::table('recurring_buffer_rules')->insertGetId(['location_slug' => $data['location'] ?? 'ikast', 'name' => $data['name'], 'weekdays' => json_encode(array_values($data['weekdays'])), 'start_local_time' => $data['startLocalTime'], 'duration_minutes' => $data['durationMinutes'], 'valid_from' => $data['validFrom'] ?? null, 'valid_to' => $data['validTo'] ?? null, 'internal_bookable' => $data['internalBookable'], 'active' => true, 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('capacity.recurring_buffer.created', 'recurring_buffer_rule', $id, null, $data);

        return response()->json(['id' => (string) $id], 201);
    }

    public function updateRecurringBufferV2(Request $request, int $buffer): JsonResponse
    {
        $current = DB::table('recurring_buffer_rules')->where('id', $buffer)->first();
        abort_unless($current, 404, 'Den faste buffer findes ikke');
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'weekdays' => ['required', 'array', 'min:1'], 'weekdays.*' => ['integer', 'between:1,7'], 'startLocalTime' => ['required', 'date_format:H:i'], 'durationMinutes' => ['required', 'integer', 'min:20', 'max:240'], 'validFrom' => ['nullable', 'date'], 'validTo' => ['nullable', 'date', 'after_or_equal:validFrom'], 'internalBookable' => ['required', 'boolean'], 'active' => ['nullable', 'boolean']]);
        $values = ['name' => $data['name'], 'weekdays' => json_encode(array_values($data['weekdays'])), 'start_local_time' => $data['startLocalTime'], 'duration_minutes' => $data['durationMinutes'], 'valid_from' => $data['validFrom'] ?? null, 'valid_to' => $data['validTo'] ?? null, 'internal_bookable' => $data['internalBookable'], 'active' => $data['active'] ?? true, 'updated_at' => now()];
        DB::table('recurring_buffer_rules')->where('id', $buffer)->update($values);
        $this->audit('capacity.recurring_buffer.updated', 'recurring_buffer_rule', $buffer, (array) $current, $values);

        return response()->json(['buffer' => DB::table('recurring_buffer_rules')->where('id', $buffer)->first()]);
    }

    public function deleteRecurringBufferV2(int $buffer): JsonResponse
    {
        $current = DB::table('recurring_buffer_rules')->where('id', $buffer)->first();
        abort_unless($current, 404, 'Den faste buffer findes ikke');
        DB::table('recurring_buffer_rules')->where('id', $buffer)->update(['active' => false, 'updated_at' => now()]);
        $this->audit('capacity.recurring_buffer.deleted', 'recurring_buffer_rule', $buffer, (array) $current, ['active' => false, 'scope' => 'series']);

        return response()->json(['ok' => true]);
    }

    public function createScheduleOverrideV2(Request $request): JsonResponse
    {
        $data = $request->validate(['location' => ['nullable', 'string', 'max:80'], 'date' => ['required', 'date_format:Y-m-d'], 'startsAt' => ['required', 'date_format:H:i'], 'endsAt' => ['required', 'date_format:H:i', 'after:startsAt'], 'overrideType' => ['required', 'in:FORCE_PUBLIC_OPEN,FORCE_INTERNAL_ONLY,FORCE_BUFFER,FORCE_CLOSED'], 'reason' => ['required', 'string', 'max:255'], 'locked' => ['nullable', 'boolean']]);
        if ($data['overrideType'] === 'FORCE_PUBLIC_OPEN') {
            abort_unless(Permission::allows('capacity.buffer.release') || Permission::allows('capacity.manage'), 403, 'Mangler rettighed til at åbne tiden');
        }
        $conflicts = DB::table('bookings')->whereDate('starts_at', $data['date'])->whereNotIn('status', ['cancelled', 'no_show'])
            ->where('starts_at', '<', $data['date'].' '.$data['endsAt'])->where('ends_at', '>', $data['date'].' '.$data['startsAt'])->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        $id = DB::table('schedule_overrides')->insertGetId(['location_slug' => $data['location'] ?? 'ikast', 'starts_at' => $data['date'].' '.$data['startsAt'], 'ends_at' => $data['date'].' '.$data['endsAt'], 'override_type' => $data['overrideType'], 'buffer_type' => $data['overrideType'] === 'FORCE_BUFFER' ? 'MANUAL' : null, 'reason' => $data['reason'], 'locked' => $data['locked'] ?? true, 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('capacity.override.created', 'schedule_override', $id, null, $data + ['conflicts' => $conflicts]);

        return response()->json(['id' => (string) $id, 'conflicts' => $conflicts], 201);
    }

    public function deleteScheduleOverrideV2(int $override): JsonResponse
    {
        $current = DB::table('schedule_overrides')->where('id', $override)->first();
        abort_unless($current, 404, 'Den manuelle ændring findes ikke');
        abort_if($current->buffer_type === 'MOVED' && $current->related_booking_id, 409, 'En flyttet buffer følger bookingen og kan ikke fjernes separat');
        DB::table('schedule_overrides')->where('id', $override)->delete();
        $this->audit('capacity.override.deleted', 'schedule_override', $override, (array) $current, null);

        return response()->json(['ok' => true]);
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
        $invoices = DB::table('invoice_drafts')->orderBy('customer_name')->get()->map(function ($invoice) {
            $lines = DB::table('invoice_lines')->where('invoice_draft_id', $invoice->id)->orderBy('id')->get();
            $checks = $this->invoiceChecks($invoice, $lines);

            return array_merge((array) $invoice, ['lines' => $lines, 'checks' => $checks, 'blockingErrors' => collect($checks)->where('severity', 'error')->values()->all(), 'warnings' => collect($checks)->where('severity', 'warning')->values()->all()]);
        });

        return response()->json([
            'periods' => DB::table('invoice_periods')->orderByDesc('period_start')->get(),
            'invoices' => $invoices,
            'dineroReady' => filled(config('services.dinero.base_url')),
        ]);
    }

    public function updateInvoice(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'integer', 'exists:invoice_drafts,id'], 'description' => ['required', 'string'], 'quantity' => ['required', 'numeric', 'min:0.01'], 'unitPriceOre' => ['required', 'integer', 'min:0'], 'status' => ['required', 'in:Klargøres,requires_action'], 'reason' => ['nullable', 'string', 'max:500']]);
        $current = DB::table('invoice_drafts')->where('id', $data['id'])->first();
        abort_if($current?->locked_at, 409, 'Fakturaen er låst efter godkendelse');
        $changes = ['description' => $data['description'], 'quantity' => $data['quantity'], 'unit_price_ore' => $data['unitPriceOre'], 'status' => $data['status'], 'updated_at' => now()];
        $fields = ['description' => [$current->description, $data['description']], 'quantity' => [$current->quantity, $data['quantity']], 'unit_price_ore' => [$current->unit_price_ore, $data['unitPriceOre']]];
        foreach ($fields as $field => [$old, $new]) {
            if ((string) $old !== (string) $new) {
                abort_if(blank($data['reason'] ?? null), 422, 'Angiv en begrundelse for manuelle ændringer');
                DB::table('invoice_revisions')->insert(['invoice_draft_id' => $data['id'], 'field' => $field, 'original_value' => (string) $old, 'new_value' => (string) $new, 'reason' => $data['reason'], 'user_id' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('invoice_drafts')->where('id', $data['id'])->update($changes);
        DB::table('invoice_lines')->where('invoice_draft_id', $data['id'])->where('source_system', 'legacy')->update(['description' => $data['description'], 'quantity' => $data['quantity'], 'unit_price_ore' => $data['unitPriceOre'], 'updated_at' => now()]);
        $this->audit('invoice.updated', 'invoice_draft', $data['id'], null, $data);

        return response()->json(['ok' => true]);
    }

    public function approveInvoices(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['integer', 'exists:invoice_drafts,id']]);
        $invoices = DB::table('invoice_drafts')->whereIn('id', $data['ids'])->get();
        $blocked = $invoices->mapWithKeys(function ($invoice) {
            $checks = $this->invoiceChecks($invoice, DB::table('invoice_lines')->where('invoice_draft_id', $invoice->id)->get());

            return [$invoice->id => collect($checks)->where('severity', 'error')->pluck('message')->values()->all()];
        })->filter(fn ($errors) => count($errors) > 0);
        if ($blocked->isNotEmpty()) {
            return response()->json(['error' => 'Nogle fakturaer har blokerende fejl', 'blocked' => $blocked], 422);
        }
        DB::table('invoice_drafts')->whereIn('id', $data['ids'])->update(['status' => 'APPROVED', 'external_status' => 'NOT_SENT', 'approved_by' => Auth::id(), 'approved_at' => now(), 'locked_at' => now(), 'updated_at' => now()]);
        foreach ($data['ids'] as $id) {
            $this->audit('invoice.approved', 'invoice_draft', $id, null, ['status' => 'APPROVED']);
        }

        return response()->json(['ok' => true, 'approved' => count($data['ids'])]);
    }

    private function invoiceChecks(object $invoice, $lines): array
    {
        $checks = [];
        if ($invoice->billing_method === 'efaktura' && blank($invoice->customer_name)) {
            $checks[] = ['code' => 'customer_missing', 'severity' => 'error', 'message' => 'Kunden mangler oplysninger til e-faktura'];
        }
        if ((int) $invoice->unit_price_ore === 0 || $lines->contains(fn ($line) => (int) $line->unit_price_ore === 0)) {
            $checks[] = ['code' => 'zero_price', 'severity' => 'error', 'message' => 'En ydelse har en pris på 0 kr.'];
        }
        if ($lines->isEmpty()) {
            $checks[] = ['code' => 'no_lines', 'severity' => 'error', 'message' => 'Fakturaen har ingen fakturalinjer'];
        }
        if (blank($invoice->payment_terms)) {
            $checks[] = ['code' => 'payment_terms_missing', 'severity' => 'error', 'message' => 'Betalingsbetingelser skal angives før godkendelse'];
        }
        if ($invoice->external_status !== 'NOT_SENT') {
            $checks[] = ['code' => 'external_state', 'severity' => 'info', 'message' => 'Dinero-status: '.$invoice->external_status];
        }

        return $checks;
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

    public function smsSettings(): JsonResponse
    {
        $settings = DB::table('sms_settings')->first();
        $settings ??= (object) ['enabled' => false, 'provider' => 'gatewayapi', 'sender_id' => 'MB Bilsyn', 'reminder_time' => '15:00:00', 'quiet_start' => '21:00:00', 'quiet_end' => '07:00:00', 'private_confirmation' => true, 'private_reminder' => true, 'private_change' => true, 'business_enabled' => false, 'auto_retry' => true, 'max_retry_attempts' => 3];

        return response()->json(['settings' => $settings, 'providerReady' => false]);
    }

    public function updateSmsSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'senderId' => ['required', 'string', 'max:11'], 'reminderTime' => ['required', 'date_format:H:i'],
            'quietStart' => ['required', 'date_format:H:i'], 'quietEnd' => ['required', 'date_format:H:i'],
            'privateConfirmation' => ['required', 'boolean'], 'privateReminder' => ['required', 'boolean'], 'privateChange' => ['required', 'boolean'],
            'businessEnabled' => ['required', 'boolean'], 'autoRetry' => ['required', 'boolean'], 'maxRetryAttempts' => ['required', 'integer', 'between:0,9'],
        ]);
        $values = ['sender_id' => $data['senderId'], 'reminder_time' => $data['reminderTime'], 'quiet_start' => $data['quietStart'], 'quiet_end' => $data['quietEnd'], 'private_confirmation' => $data['privateConfirmation'], 'private_reminder' => $data['privateReminder'], 'private_change' => $data['privateChange'], 'business_enabled' => $data['businessEnabled'], 'auto_retry' => $data['autoRetry'], 'max_retry_attempts' => $data['maxRetryAttempts'], 'updated_at' => now()];
        DB::table('sms_settings')->updateOrInsert(['id' => 1], $values + ['enabled' => false, 'provider' => 'gatewayapi', 'created_at' => now()]);
        $this->audit('sms.settings.updated', 'sms_settings', 1, null, $data);

        return response()->json(['settings' => DB::table('sms_settings')->where('id', 1)->first(), 'providerReady' => false]);
    }

    public function smsTemplates(): JsonResponse
    {
        return response()->json(['templates' => DB::table('sms_templates')->orderBy('audience')->orderBy('name')->get()]);
    }

    public function updateSmsTemplate(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:1600'], 'enabled' => ['required', 'boolean']]);
        $allowed = ['customer_name', 'customerName', 'company_name', 'registration_number', 'registration', 'inspection_type', 'booking_date', 'date', 'booking_time', 'time', 'location_name', 'location_address', 'company_phone', 'booking_reference', 'booking_link'];
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $data['body'], $matches);
        $unknown = array_values(array_diff(array_unique($matches[1] ?? []), $allowed));
        if ($unknown) {
            return response()->json(['error' => 'Ukendt skabelonfelt: '.implode(', ', $unknown)], 422);
        }
        $template = DB::table('sms_templates')->where('code', $code)->first();
        if (! $template) {
            return response()->json(['error' => 'SMS-skabelonen findes ikke'], 404);
        }
        DB::table('sms_templates')->where('id', $template->id)->update(['body' => $data['body'], 'enabled' => $data['enabled'], 'version' => ((int) $template->version) + 1, 'updated_at' => now()]);

        return response()->json(['template' => DB::table('sms_templates')->where('id', $template->id)->first()]);
    }

    public function resetSmsTemplate(string $code): JsonResponse
    {
        $defaults = ['PRIVATE_BOOKING_CONFIRMATION' => 'Hej {{customerName}}. Din tid hos Midtjysk Bilsyn er {{date}} kl. {{time}}. Svar gerne på denne SMS ved spørgsmål.', 'PRIVATE_BOOKING_REMINDER' => 'Påmindelse: Du har tid hos Midtjysk Bilsyn {{date}} kl. {{time}} for {{registration}}. Vi glæder os til at se dig.', 'PRIVATE_BOOKING_CHANGED' => 'Din tid hos Midtjysk Bilsyn er ændret til {{date}} kl. {{time}}. Svar gerne på denne SMS ved spørgsmål.', 'BUSINESS_BOOKING_CONFIRMATION' => 'Booking hos Midtjysk Bilsyn: {{date}} kl. {{time}} · {{registration}}.', 'BUSINESS_BOOKING_REMINDER' => 'Påmindelse om booking hos Midtjysk Bilsyn {{date}} kl. {{time}} · {{registration}}.', 'BUSINESS_BOOKING_CHANGED' => 'Booking ændret hos Midtjysk Bilsyn til {{date}} kl. {{time}} · {{registration}}.'];
        if (! isset($defaults[$code]) || ! DB::table('sms_templates')->where('code', $code)->exists()) {
            return response()->json(['error' => 'SMS-skabelonen findes ikke'], 404);
        }
        DB::table('sms_templates')->where('code', $code)->update(['body' => $defaults[$code], 'enabled' => true, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);

        return response()->json(['template' => DB::table('sms_templates')->where('code', $code)->first()]);
    }

    public function businessSmsPreferences(int $customer): JsonResponse
    {
        abort_unless(DB::table('customers')->where('id', $customer)->where('customer_type', 'business')->exists(), 404, 'Erhvervskunden findes ikke');
        $preferences = DB::table('business_sms_preferences')->where('customer_id', $customer)->first();
        if (! $preferences) {
            DB::table('business_sms_preferences')->insert(['customer_id' => $customer, 'created_at' => now(), 'updated_at' => now()]);
            $preferences = DB::table('business_sms_preferences')->where('customer_id', $customer)->first();
        }

        return response()->json(['preferences' => $preferences]);
    }

    public function updateBusinessSmsPreferences(Request $request, int $customer): JsonResponse
    {
        abort_unless(DB::table('customers')->where('id', $customer)->where('customer_type', 'business')->exists(), 404, 'Erhvervskunden findes ikke');
        $data = $request->validate(['confirmationEnabled' => ['required', 'boolean'], 'reminderEnabled' => ['required', 'boolean'], 'changeEnabled' => ['required', 'boolean']]);
        DB::table('business_sms_preferences')->updateOrInsert(['customer_id' => $customer], ['confirmation_enabled' => $data['confirmationEnabled'], 'reminder_enabled' => $data['reminderEnabled'], 'change_enabled' => $data['changeEnabled'], 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['preferences' => DB::table('business_sms_preferences')->where('customer_id', $customer)->first()]);
    }

    public function smsMessages(Request $request): JsonResponse
    {
        $rows = DB::table('sms_messages')->leftJoin('bookings', 'bookings.id', '=', 'sms_messages.booking_id')->leftJoin('customers', 'customers.id', '=', 'bookings.customer_id')->latest('sms_messages.created_at')->limit(100)->get(['sms_messages.id', 'sms_messages.kind', 'sms_messages.status', 'sms_messages.recipient_masked', 'sms_messages.scheduled_at', 'sms_messages.sent_at', 'sms_messages.delivered_at', 'sms_messages.error_message', 'customers.display_name as customer']);

        return response()->json(['messages' => $rows]);
    }

    private function bookingInput(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->all(), ['customer' => ['required', 'string', 'max:160'], 'customerType' => ['required', 'in:private,business'], 'phone' => ['nullable', 'string', 'max:32'], 'email' => ['nullable', 'email', 'max:160'], 'plate' => ['required', 'string', 'max:12'], 'vehicle' => ['nullable', 'string', 'max:200'], 'requisitionNumber' => ['nullable', 'string', 'max:100'], 'date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i'], 'inspection' => ['required', 'string', 'max:80'], 'status' => ['nullable', 'string', 'max:32'], 'source' => ['nullable', 'in:manual,public_web,phone,business_portal'], 'bufferAction' => ['nullable', 'in:use']]);

        return $validator->fails() ? response()->json(['error' => 'Kunde, dato, tid og registreringsnummer skal udfyldes', 'errors' => $validator->errors()], 422) : $validator->validated();
    }

    private function issueBookingManagementToken(int $bookingId): array
    {
        $rawToken = Str::random(64);
        $startsAt = CarbonImmutable::parse(DB::table('bookings')->where('id', $bookingId)->value('starts_at'));
        DB::table('booking_management_tokens')->insert([
            'booking_id' => $bookingId,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => $startsAt->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['token' => $rawToken, 'path' => '/booking/manage?token='.urlencode($rawToken)];
    }

    private function validBookingManagementToken(string $rawToken): ?object
    {
        return DB::table('booking_management_tokens')
            ->where('token_hash', hash('sha256', $rawToken))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    private function managedBookingSummary(int $bookingId): array
    {
        $booking = DB::table('bookings')
            ->join('vehicles', 'vehicles.id', '=', 'bookings.vehicle_id')
            ->leftJoin('inspection_types', 'inspection_types.name', '=', 'bookings.inspection_type')
            ->where('bookings.id', $bookingId)
            ->first([
                'bookings.id', 'bookings.starts_at', 'bookings.inspection_type', 'bookings.status',
                'vehicles.registration_normalized', 'inspection_types.id as inspection_type_id',
            ]);
        abort_unless($booking, 404, 'Bookingen findes ikke');
        $startsAt = CarbonImmutable::parse($booking->starts_at);

        return [
            'id' => (string) $booking->id,
            'date' => $startsAt->format('Y-m-d'),
            'time' => $startsAt->format('H:i'),
            'inspection' => $booking->inspection_type,
            'bookingTypeId' => $booking->inspection_type_id ? (string) $booking->inspection_type_id : null,
            'plate' => $this->formatPlate($booking->registration_normalized),
            'status' => $booking->status,
            'canChange' => ! in_array($booking->status, ['cancelled', 'no_show'], true) && $startsAt->gt(now()->addMinutes(self::PUBLIC_MINIMUM_NOTICE_MINUTES)),
        ];
    }

    private function publicFrontendUrl(): string
    {
        $origin = (string) request()->header('x-public-origin', '');
        $configured = rtrim((string) config('app.frontend_url', config('app.url')), '/');
        $host = mb_strtolower((string) parse_url($origin, PHP_URL_HOST));
        $configuredHost = mb_strtolower((string) parse_url($configured, PHP_URL_HOST));
        $extraHosts = collect(config('app.trusted_frontend_hosts', []))
            ->map(fn ($trustedHost) => mb_strtolower((string) $trustedHost))
            ->filter()
            ->all();
        $localHosts = app()->environment(['local', 'testing']) ? ['localhost', '127.0.0.1'] : [];
        $trustedHost = $host !== '' && ($host === $configuredHost || in_array($host, [...$extraHosts, ...$localHosts], true));
        if ($trustedHost && filter_var($origin, FILTER_VALIDATE_URL) && in_array(parse_url($origin, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return rtrim($origin, '/');
        }

        return $configured;
    }

    private function appendManagementLinkToSms(int $bookingId, string $manageUrl): void
    {
        DB::table('sms_messages')->where('booking_id', $bookingId)->whereIn('status', ['held', 'DRAFT', 'SCHEDULED', 'QUEUED'])->get(['id', 'body'])->each(function ($message) use ($manageUrl) {
            $body = trim((string) $message->body);
            if (! str_contains($body, '/booking/manage?token=')) {
                $body .= "\nÆndr/afbestil: {$manageUrl}";
                DB::table('sms_messages')->where('id', $message->id)->update([
                    'body' => $body,
                    'segment_count' => $this->smsSegments($body),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    private function sendBookingChangeEmail(?string $email, string $subject, string $body): void
    {
        if (! $email) {
            return;
        }
        try {
            Mail::raw("{$body}\n\nVenlig hilsen\nMidtjysk Bilsyn", fn ($mail) => $mail->to($email)->subject($subject.' – Midtjysk Bilsyn'));
        } catch (\Throwable) {
        }
    }

    private function sendBusinessPortalPasswordReset(object $user): void
    {
        try {
            $token = Str::random(64);
            DB::transaction(function () use ($user, $token) {
                DB::table('business_portal_password_resets')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
                DB::table('business_portal_password_resets')->insert([
                    'user_id' => $user->id,
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => now()->addHour(),
                    'created_at' => now(),
                ]);
            });
            $url = $this->publicFrontendUrl().'/branchekunde/reset?token='.urlencode($token).'&email='.urlencode($user->email);
            Mail::raw(
                "Hej {$user->name}\n\nDu kan vælge en ny adgangskode til branchekundeportalen via linket nedenfor. Linket er gyldigt i 60 minutter.\n\n{$url}\n\nVenlig hilsen\nMidtjysk Bilsyn",
                fn ($mail) => $mail->to($user->email)->subject('Vælg en ny adgangskode – Midtjysk Bilsyn')
            );
        } catch (\Throwable) {
        }
    }

    private function displayDate(string $startsAt): string
    {
        return CarbonImmutable::parse($startsAt)->format('d.m.Y').' kl. '.CarbonImmutable::parse($startsAt)->format('H:i');
    }

    private function queueSms(int $bookingId, string $kind, int $customerId, ?string $phone, CarbonImmutable $startsAt, string $registration, string $audience = 'private'): void
    {
        $settings = DB::table('sms_settings')->where('id', 1)->first();
        $templateCode = strtoupper($audience).'_BOOKING_'.strtoupper($kind);
        $template = DB::table('sms_templates')->where('code', $templateCode)->first();
        $normalized = $this->normalizePhone($phone);
        if ($audience === 'business' && ! $normalized) {
            return;
        }
        $body = $template?->body;
        if ($body) {
            $body = strtr($body, ['{{date}}' => $startsAt->format('d.m.Y'), '{{time}}' => $startsAt->format('H:i'), '{{registration}}' => $this->formatPlate($registration), '{{customerName}}' => (string) DB::table('customers')->where('id', $customerId)->value('display_name')]);
        }
        $scheduled = $kind === 'reminder' ? $startsAt->subDay() : now();
        DB::table('sms_messages')->insertOrIgnore(['booking_id' => $bookingId, 'kind' => $kind, 'template_code' => $templateCode, 'recipient_hash' => $normalized ? hash('sha256', $normalized) : null, 'recipient_masked' => $this->maskPhone($normalized), 'sender_id' => $settings?->sender_id ?? 'MB Bilsyn', 'body' => $body, 'status' => 'held', 'idempotency_key' => 'booking:'.$bookingId.':'.$kind, 'attempts' => 0, 'retry_count' => 0, 'segment_count' => $this->smsSegments($body ?? ''), 'available_at' => $scheduled, 'scheduled_at' => $scheduled, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function normalizePhone(?string $phone): ?string
    {
        $value = preg_replace('/[^0-9+]/', '', (string) $phone);
        if ($value === '') {
            return null;
        }
        if (str_starts_with($value, '00')) {
            $value = '+'.substr($value, 2);
        }
        if (! str_starts_with($value, '+') && strlen($value) === 8) {
            $value = '+45'.$value;
        }

        return preg_match('/^\+[1-9][0-9]{7,14}$/', $value) ? $value : null;
    }

    private function maskPhone(?string $phone): ?string
    {
        return $phone ? substr($phone, 0, 4).' ··· '.substr($phone, -2) : null;
    }

    private function smsSegments(string $body): int
    {
        if ($body === '') {
            return 1;
        }
        $limit = preg_match('/[^\x00-\x7F]/', $body) ? 70 : 160;
        $partLimit = strlen($body) > $limit ? ($limit - (preg_match('/[^\x00-\x7F]/', $body) ? 3 : 7)) : $limit;

        return max(1, (int) ceil(strlen($body) / $partLimit));
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
        $type = $this->activeInspectionType($name);

        return ['name' => $name, 'requiredSlots' => max(1, (int) ($type->required_slots ?? 1))];
    }

    private function activeInspectionType(string $name): ?object
    {
        return DB::table('inspection_types')->where('name', trim($name))->where('is_active', true)->first();
    }

    private function businessPortalInspectionType(object $settings, string $name): ?object
    {
        $type = $this->activeInspectionType($name);
        if (! $type) {
            return null;
        }
        $allowed = $settings->allowed_inspection_types ? json_decode($settings->allowed_inspection_types, true) : [];

        return ! is_array($allowed) || $allowed === [] || in_array($type->name, $allowed, true) ? $type : null;
    }

    private function businessPortalInspectionTypes(object $settings): array
    {
        $allowed = $settings->allowed_inspection_types ? json_decode($settings->allowed_inspection_types, true) : [];

        return DB::table('inspection_types')->where('is_active', true)
            ->when(is_array($allowed) && $allowed !== [], fn ($query) => $query->whereIn('name', $allowed))
            ->orderBy('sort_order')->get(['id', 'name', 'required_slots'])
            ->map(fn ($type) => ['id' => (string) $type->id, 'name' => $type->name, 'requiredSlots' => (int) $type->required_slots])
            ->values()->all();
    }

    private function requestStartsAt(Request $request): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d H:i', $request->string('date')->toString().' '.$request->string('time')->toString(), config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
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

    private function availabilityForDate(string $date, ?string $inspection = null, ?int $excludeBookingId = null, string $scope = 'public'): array
    {
        if (config('capacity_planner.enabled') && Schema::hasTable('capacity_profiles_v2')) {
            $plan = app(CapacityPlannerV2::class)->buildDayPlan((string) config('capacity_planner.default_location', 'ikast'), $date, $inspection, $excludeBookingId);
            $plan['availableSlots'] = $scope === 'internal' ? $plan['internalAvailableSlots'] : $plan['publicAvailableSlots'];

            return $plan;
        }
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
        $employeeIds = DB::table('employees')->where('active', true)->where('booking_capacity', true)->when(Schema::hasColumn('employees', 'archived'), fn ($query) => $query->where('archived', false))->pluck('id');
        $absentIds = DB::table('employee_absences')->whereIn('employee_id', $employeeIds)->whereDate('date_from', '<=', $day)->whereDate('date_to', '>=', $day)->get()->filter(function ($absence) use ($day) {
            if (empty($absence->start_at) || empty($absence->end_at) || (bool) ($absence->all_day ?? true)) {
                return true;
            }

            return CarbonImmutable::parse($absence->start_at)->lt($day->endOfDay()) && CarbonImmutable::parse($absence->end_at)->gt($day->startOfDay());
        })->pluck('employee_id')->all();
        $anchorFallback = CarbonImmutable::parse('2026-08-03')->startOfWeek(CarbonImmutable::MONDAY);
        $workRules = DB::table('employee_work_rules')->whereIn('employee_id', $employeeIds)->where('weekday', $day->isoWeekday())->where('working', true)->get()->filter(function ($rule) use ($day, $anchorFallback) {
            if (isset($rule->active) && ! (bool) $rule->active) {
                return false;
            }
            if (! empty($rule->valid_from) && $day->lt(CarbonImmutable::parse($rule->valid_from))) {
                return false;
            }
            if (! empty($rule->valid_to) && $day->gt(CarbonImmutable::parse($rule->valid_to))) {
                return false;
            }
            $cycleWeeks = max(1, min(3, (int) ($rule->cycle_weeks ?? 1)));
            $anchor = ! empty($rule->anchor_monday_date) ? CarbonImmutable::parse($rule->anchor_monday_date)->startOfWeek(CarbonImmutable::MONDAY) : $anchorFallback;
            $weeksSinceAnchor = (int) floor($anchor->diffInDays($day->startOfWeek(CarbonImmutable::MONDAY), false) / 7);
            $activeCycleWeek = (($weeksSinceAnchor % $cycleWeeks) + $cycleWeeks) % $cycleWeeks + 1;

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

    /**
     * @param  array<int, array{key: string, name: string, date: string}>  $holidays
     * @return array<int, array<string, mixed>>
     */
    private function decorateHolidaySuggestions(array $holidays): array
    {
        $rules = DB::table('availability_rules')->get();
        $today = now('Europe/Copenhagen')->toDateString();
        $weekdayNames = [1 => 'mandag', 2 => 'tirsdag', 3 => 'onsdag', 4 => 'torsdag', 5 => 'fredag', 6 => 'lørdag', 7 => 'søndag'];

        return collect($holidays)->map(function (array $holiday) use ($rules, $today, $weekdayNames) {
            $date = CarbonImmutable::parse($holiday['date']);
            $weekdayRules = $rules->where('weekday', $date->isoWeekday());
            $periodClosure = $rules->first(fn ($rule) => in_array($rule->kind, ['holiday', 'vacation'], true)
                && $rule->date_from && $rule->date_to
                && (string) $rule->date_from <= $holiday['date'] && (string) $rule->date_to >= $holiday['date']);
            $weeklyClosed = ! $weekdayRules->contains('kind', 'opening_hours') || $weekdayRules->contains('kind', 'closed_day');
            $closedBy = $periodClosure?->label;
            if (! $closedBy && $weeklyClosed) {
                $closedBy = 'Fast lukkedag ('.$weekdayNames[$date->isoWeekday()].')';
            }

            return $holiday + [
                'weekday' => $weekdayNames[$date->isoWeekday()],
                'weekend' => $date->isWeekend(),
                'past' => $holiday['date'] < $today,
                'alreadyClosed' => (bool) ($periodClosure || $weeklyClosed),
                'closedBy' => $closedBy,
            ];
        })->values()->all();
    }

    private function lockCapacityDay(string $location, string $date): void
    {
        if (! Schema::hasTable('capacity_day_locks')) {
            return;
        }
        DB::table('capacity_day_locks')->insertOrIgnore(['location_slug' => $location, 'date' => $date, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('capacity_day_locks')->where('location_slug', $location)->whereDate('date', $date)->lockForUpdate()->first();
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

    private function employeeInitials(string $name): string
    {
        $parts = collect(preg_split('/\s+/', trim($name)) ?: [])->filter()->values();
        if ($parts->count() > 1) {
            return mb_strtoupper(mb_substr($parts->first(), 0, 1).mb_substr($parts->last(), 0, 1));
        }

        return mb_strtoupper(mb_substr((string) $parts->first(), 0, 2)) ?: 'MB';
    }
}
