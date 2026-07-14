<?php

namespace App\Http\Middleware;

use App\Support\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminDashboardUnlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! AdminAccess::hasAnyAdminAccess($request)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Unlock as an admin employee or sign in with an admin account first.',
                ], 403);
            }

            return redirect()->route('timeclock.unlock');
        }

        return $next($request);
    }
}
