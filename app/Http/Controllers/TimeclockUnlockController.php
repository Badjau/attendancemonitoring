<?php

namespace App\Http\Controllers;

use App\Http\Requests\TimeclockUnlockRequest;
use App\Models\Employee;
use App\Models\User;
use App\Services\TimeclockUnlockService;
use App\Support\PasswordVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TimeclockUnlockController extends Controller
{
    public function __construct(protected TimeclockUnlockService $timeclockUnlockService) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->boolean('locked')) {
            $this->clearUnlockSessions($request);
        }

        if ($request->session()->has('timeclock_unlocked_by')) {
            return redirect()->route('home');
        }

        return Inertia::render('TimeclockUnlock', [
            'zktecoBridgeUrl' => config('services.zkteco.bridge_url'),
        ]);
    }

    public function store(TimeclockUnlockRequest $request): JsonResponse
    {
        try {
            if ($request->input('method') === 'admin') {
                return $this->storeAdminUnlock($request);
            }

            $request->session()->forget([
                'admin_unlocked_by',
                'admin_unlocked_at',
                'admin_password_unlocked_by',
                'admin_password_unlocked_at',
            ]);

            $authorizedUser = $this->timeclockUnlockService->store($request);
            $authorizedUser->loadMissing('employee');
            $adminUser = $this->adminUserForAuthorizedEmployee($authorizedUser->employee);
            $isAdmin = $adminUser || $authorizedUser->employee?->role === Employee::ROLE_ADMIN;

            if ($adminUser) {
                Auth::login($adminUser);

                $request->session()->regenerate();
                $request->session()->put([
                    'admin_password_unlocked_by' => $adminUser->id,
                    'admin_password_unlocked_at' => now()->toDateTimeString(),
                ]);
                $request->session()->save();
            }

            return response()->json([
                'message' => $isAdmin ? 'Admin unlocked.' : 'Timeclock unlocked.',
                'redirect' => $isAdmin ? '/admin' : route('home'),
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
        $this->clearUnlockSessions(request());

        return redirect()->route('timeclock.unlock', ['locked' => 1]);
    }

    private function clearUnlockSessions(Request $request): void
    {
        $request->session()->forget([
            'admin_unlocked_by',
            'admin_unlocked_at',
            'admin_password_unlocked_by',
            'admin_password_unlocked_at',
            'timeclock_unlocked_by',
            'timeclock_unlocked_at',
        ]);
    }

    private function adminUserForAuthorizedEmployee(?Employee $employee): ?User
    {
        if (! $employee) {
            return null;
        }

        return User::query()
            ->where('employee_id', $employee->id)
            ->where('is_admin', true)
            ->first();
    }

    private function storeAdminUnlock(TimeclockUnlockRequest $request): JsonResponse
    {
        $username = $request->string('username')->trim()->toString();
        $password = $request->string('credential')->toString();

        $user = User::query()
            ->where('is_admin', true)
            ->where(function ($query) use ($username) {
                $query
                    ->where('username', $username)
                    ->orWhere('email', $username);
            })
            ->first();

        if (! $user || ! PasswordVerifier::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => 'The admin username or password is incorrect.',
            ]);
        }

        Auth::login($user);

        $request->session()->regenerate();
        $request->session()->forget([
            'admin_unlocked_by',
            'admin_unlocked_at',
            'timeclock_unlocked_by',
            'timeclock_unlocked_at',
        ]);
        $request->session()->put([
            'admin_password_unlocked_by' => $user->id,
            'admin_password_unlocked_at' => now()->toDateTimeString(),
        ]);
        $request->session()->save();

        return response()->json([
            'message' => 'Admin unlocked.',
            'redirect' => '/admin',
        ]);
    }
}
