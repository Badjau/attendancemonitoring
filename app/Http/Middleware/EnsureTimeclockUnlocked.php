<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTimeclockUnlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! session()->has('timeclock_unlocked_by')) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Unlock the timeclock first.',
                ], 423);
            }

            return redirect()->route('timeclock.unlock');
        }

        return $next($request);
    }
}
