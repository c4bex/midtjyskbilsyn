<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) env('BOOKING_API_TOKEN', '');
        if (Auth::check() || ($expected !== '' && hash_equals($expected, (string) $request->bearerToken()))) {
            return $next($request);
        }

        return response()->json(['error' => 'Du skal være logget ind'], 401);
    }
}
