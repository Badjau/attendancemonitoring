<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminDashboardUnlocked
{
    public function handle(Request $request, Closure $next): Response
    {
        $employeeId = session('admin_unlocked_by');

        $isAdmin = $employeeId
            && Employee::query()
                ->whereKey($employeeId)
                ->where('role', Employee::ROLE_ADMIN)
                ->exists();

        if (! $isAdmin) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Unlock as an admin employee first.',
                ], 403);
            }

            return redirect()->route('timeclock.unlock');
        }

        return $next($request);
    }
}
