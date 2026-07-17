<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureKioskApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = (string) config('services.kiosk.token', '');
        $providedToken = (string) ($request->bearerToken() ?: $request->header('X-Kiosk-Api-Token', ''));

        if ($expectedToken === '' || $providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            return response()->json([
                'message' => 'Unauthorized kiosk API request.',
            ], 401);
        }

        return $next($request);
    }
}
