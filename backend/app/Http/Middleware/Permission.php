<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Permission
{
    public function handle(Request $request, Closure $next, string $permission)
    {
        $role = (string) env('BOOKING_API_ROLE', 'Teknisk ansvarlig / Ejer');
        $permissions = [
            'Teknisk ansvarlig / Ejer' => ['bookings.read', 'bookings.write', 'customers.write', 'imports.write', 'invoices.write', 'employees.write', 'settings.write'],
            'Synsinspektør' => ['bookings.read', 'bookings.write', 'customers.write'],
            'Bogholder / blæksprut' => ['bookings.read', 'customers.read', 'invoices.write'],
        ];
        if (!in_array($permission, $permissions[$role] ?? [], true)) return response()->json(['error' => 'Mangler rettighed: '.$permission], 403);
        return $next($request);
    }
}
