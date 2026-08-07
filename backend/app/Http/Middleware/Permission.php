<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Permission
{
    public static function catalog(): array
    {
        return [
            'bookings.read' => ['label' => 'Se bookinger', 'group' => 'Booking'],
            'bookings.write' => ['label' => 'Oprette og rette bookinger', 'group' => 'Booking'],
            'customers.read' => ['label' => 'Se kunder og køretøjer', 'group' => 'Kunder'],
            'customers.write' => ['label' => 'Oprette og rette kunder', 'group' => 'Kunder'],
            'invoices.read' => ['label' => 'Se fakturaer og kladder', 'group' => 'Fakturering'],
            'invoices.write' => ['label' => 'Klargøre og rette fakturaer', 'group' => 'Fakturering'],
            'invoices.approve' => ['label' => 'Godkende fakturaer', 'group' => 'Fakturering'],
            'imports.read' => ['label' => 'Se importer', 'group' => 'Fakturering'],
            'imports.write' => ['label' => 'Validere importer', 'group' => 'Fakturering'],
            'employees.read' => ['label' => 'Se medarbejdere', 'group' => 'Administration'],
            'employees.write' => ['label' => 'Oprette og redigere medarbejdere', 'group' => 'Administration'],
            'employees.schedule.write' => ['label' => 'Redigere arbejdsplan', 'group' => 'Administration'],
            'employees.absence.write' => ['label' => 'Registrere ferie og fravær', 'group' => 'Administration'],
            'employees.access.write' => ['label' => 'Administrere systemadgang', 'group' => 'Administration'],
            'employees.permissions.write' => ['label' => 'Ændre roller og rettigheder', 'group' => 'Administration'],
            'settings.write' => ['label' => 'Ændre åbningstider og systemindstillinger', 'group' => 'Administration'],
            'audit.read' => ['label' => 'Se revisionshistorik', 'group' => 'Administration'],
            'ai.use' => ['label' => 'Bruge AI-assistenten', 'group' => 'AI-assistent'],
            'ai.documents.write' => ['label' => 'Administrere AI-dokumenter', 'group' => 'AI-assistent'],
            'ai.investigations.read' => ['label' => 'Se AI-undersøgelser', 'group' => 'AI-assistent'],
            'ai.investigations.write' => ['label' => 'Oprette AI-undersøgelser', 'group' => 'AI-assistent'],
            'ai.arvo.send' => ['label' => 'Sende til ARVO', 'group' => 'AI-assistent'],
        ];
    }

    public static function rolePermissions(string $role): array
    {
        return match ($role) {
            'Teknisk ansvarlig / Ejer' => array_keys(self::catalog()),
            'Synsinspektør' => ['bookings.read', 'bookings.write', 'customers.read', 'customers.write', 'employees.read', 'employees.absence.write', 'ai.use'],
            'Bogholder / blæksprut' => ['bookings.read', 'customers.read', 'invoices.read', 'invoices.write', 'imports.read', 'ai.use', 'ai.investigations.read', 'ai.investigations.write'],
            'Administrator' => ['bookings.read', 'bookings.write', 'customers.read', 'customers.write', 'invoices.read', 'invoices.write', 'imports.read', 'imports.write', 'employees.read', 'employees.write', 'employees.schedule.write', 'employees.absence.write', 'employees.access.write', 'settings.write', 'audit.read', 'ai.use'],
            default => [],
        };
    }

    public function handle(Request $request, Closure $next, string $permission)
    {
        $employee = Auth::check() ? DB::table('employees')->where('user_id', Auth::id())->first() : null;
        $role = $employee?->role ?? (string) env('BOOKING_API_ROLE', 'Teknisk ansvarlig / Ejer');
        // AI-assistenten er en fast basisfunktion for interne brugere. De øvrige
        // rettigheder starter med rollens standarder og kan derefter overriden.
        if ($role === 'Teknisk ansvarlig / Ejer') {
            // The owner must never be locked out by stale per-employee overrides.
            $allowed = true;
        } elseif ($permission === 'ai.use') {
            $allowed = true;
        } else {
            $allowed = in_array($permission, self::rolePermissions($role), true);
            if ($employee) {
                $override = DB::table('employee_permissions')
                    ->where('employee_id', $employee->id)
                    ->where('permission_key', $permission)
                    ->value('allowed');
                if ($override !== null) {
                    $allowed = (bool) $override;
                }
            }
        }
        if (! $allowed) {
            return response()->json(['error' => 'Mangler rettighed: '.$permission], 403);
        }

        return $next($request);
    }
}
