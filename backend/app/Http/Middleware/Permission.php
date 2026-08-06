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
            'invoices.write' => ['label' => 'Klargøre og rette fakturaer', 'group' => 'Fakturering'],
            'imports.write' => ['label' => 'Validere importer', 'group' => 'Fakturering'],
            'employees.write' => ['label' => 'Administrere medarbejdere og rettigheder', 'group' => 'Administration'],
            'settings.write' => ['label' => 'Ændre åbningstider og systemindstillinger', 'group' => 'Administration'],
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
            'Teknisk ansvarlig / Ejer' => ['bookings.read', 'bookings.write', 'customers.read', 'customers.write', 'imports.write', 'invoices.write', 'employees.write', 'settings.write', 'ai.use', 'ai.documents.write', 'ai.investigations.read', 'ai.investigations.write', 'ai.arvo.send'],
            'Synsinspektør' => ['bookings.read', 'bookings.write', 'customers.read', 'customers.write'],
            'Bogholder / blæksprut' => ['bookings.read', 'customers.read', 'invoices.write', 'ai.use', 'ai.investigations.read', 'ai.investigations.write'],
            default => [],
        };
    }

    public function handle(Request $request, Closure $next, string $permission)
    {
        $employee = Auth::check() ? DB::table('employees')->where('user_id', Auth::id())->first() : null;
        $role = $employee?->role ?? (string) env('BOOKING_API_ROLE', 'Teknisk ansvarlig / Ejer');
        if ($employee && DB::table('employee_permissions')->where('employee_id', $employee->id)->exists()) {
            $allowed = (bool) DB::table('employee_permissions')->where('employee_id', $employee->id)->where('permission_key', $permission)->value('allowed');
        } else {
            $allowed = in_array($permission, self::rolePermissions($role), true);
        }
        if (! $allowed) {
            return response()->json(['error' => 'Mangler rettighed: '.$permission], 403);
        }

        return $next($request);
    }
}
