<?php

namespace App\Http\Controllers;

use App\Http\Requests\TimeclockUnlockRequest;
use App\Models\Employee;
use App\Services\TimeclockUnlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TimeclockUnlockController extends Controller
{
    public function __construct(protected TimeclockUnlockService $timeclockUnlockService) {}

    public function create(): Response|RedirectResponse
    {
        if (session()->has('timeclock_unlocked_by')) {
            return redirect()->route('home');
        }

        return Inertia::render('TimeclockUnlock', [
            'zktecoBridgeUrl' => config('services.zkteco.bridge_url'),
        ]);
    }

    public function store(TimeclockUnlockRequest $request): JsonResponse
    {
        try {
            $authorizedUser = $this->timeclockUnlockService->store($request);
            $authorizedUser->loadMissing('employee');
            $isAdmin = $authorizedUser->employee?->role === Employee::ROLE_ADMIN;

            return response()->json([
                'message' => 'Timeclock unlocked.',
                'redirect' => $isAdmin ? null : route('home'),
                'destinations' => $isAdmin ? [
                    'timekeeping' => route('home'),
                    'admin' => url('/admin'),
                ] : null,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Unlock failed.',
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Unlock failed. Please try again.',
            ], 500);
        }
    }

    public function destroy(): RedirectResponse
    {
        session()->forget([
            'admin_unlocked_by',
            'admin_unlocked_at',
            'timeclock_unlocked_by',
            'timeclock_unlocked_at',
        ]);

        return redirect()->route('timeclock.unlock');
    }
}
