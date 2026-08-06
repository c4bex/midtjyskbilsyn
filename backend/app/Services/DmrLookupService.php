<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class DmrLookupService
{
    public function lookup(string $registration): array
    {
        $normalized = mb_strtoupper(preg_replace('/[^A-ZÆØÅ0-9]/u', '', $registration));
        $baseUrl = rtrim((string) config('services.dmr.base_url'), '/');
        $token = (string) config('services.dmr.token');

        if ($normalized === '' || $baseUrl === '' || $token === '') {
            return ['found' => false, 'source' => 'dmr-nas', 'unavailable' => true];
        }

        try {
            $response = Http::acceptJson()
                ->withToken($token)
                ->withHeader('x-dmr-dataset', (string) config('services.dmr.dataset', 'full'))
                ->timeout((int) config('services.dmr.timeout', 5))
                ->retry(1, 150, throw: false)
                ->get($baseUrl.'/api/dmr/vehicles', ['registration' => $normalized]);

            if (!$response->successful()) {
                return ['found' => false, 'source' => 'dmr-nas', 'unavailable' => true];
            }

            return $response->json();
        } catch (Throwable) {
            return ['found' => false, 'source' => 'dmr-nas', 'unavailable' => true];
        }
    }
}
