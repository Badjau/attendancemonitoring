<?php

namespace App\Http\Controllers;

use App\Http\Requests\TimeclockUnlockRequest;
use App\Services\AdminCredentialAccessService;
use App\Services\TimeclockUnlockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TimeclockUnlockController extends Controller
{
    public function __construct(
        protected TimeclockUnlockService $timeclockUnlockService,
        protected AdminCredentialAccessService $adminCredentialAccess,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->boolean('locked')) {
            $this->clearUnlockSessions($request);
        }

        return Inertia::render('TimeclockUnlock', [
            'isUnlocked' => $request->session()->has('timeclock_unlocked_by'),
            'zktecoBridgeUrl' => config('services.zkteco.bridge_url'),
        ]);
    }

    public function store(TimeclockUnlockRequest $request): JsonResponse|RedirectResponse
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

            if ($request->input('action') === 'lock') {
                $authorizedUser = $this->timeclockUnlockService->authorizedUserFor($request);

                if (! $authorizedUser) {
                    throw ValidationException::withMessages([
                        'credential' => 'You are not authorized to lock this timeclock.',
                    ]);
                }

                $this->clearUnlockSessions($request);

                return $this->unlockResponse($request, [
                    'message' => 'Timeclock locked.',
                    'redirect' => route('timeclock.unlock'),
                ]);
            }

            $authorizedUser = $this->timeclockUnlockService->store($request);
            $authorizedUser->loadMissing('employee');

            return $this->unlockResponse($request, [
                'message' => 'Timeclock unlocked.',
                'redirect' => route('home'),
            ]);
        } catch (ValidationException $e) {
            if (! $request->expectsJson()) {
                throw $e;
            }

            return response()->json([
                'message' => collect($e->errors())->flatten()->first() ?? 'Unlock failed.',
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            if (! $request->expectsJson()) {
                return back()->withErrors([
                    'credential' => 'Unlock failed. Please try again.',
                ])->withInput($request->except('credential'));
            }

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
        $request->session()->forget(Auth::guard()->getName());
        Auth::forgetGuards();

        $request->session()->forget([
            'admin_unlocked_by',
            'admin_unlocked_at',
            'admin_password_unlocked_by',
            'admin_password_unlocked_at',
            'timeclock_unlocked_by',
            'timeclock_unlocked_at',
        ]);
    }

    private function storeAdminUnlock(TimeclockUnlockRequest $request): JsonResponse|RedirectResponse
    {
        $user = $this->adminCredentialAccess->findAdminUser(
            $request->string('username')->trim()->toString(),
            $request->string('credential')->toString(),
        );

        if (! $user) {
            throw ValidationException::withMessages([
                'username' => 'The admin username or password is incorrect.',
            ]);
        }

        $action = $request->input('action', 'dashboard');

        if ($action === 'lock') {
            $this->clearUnlockSessions($request);

            return $this->unlockResponse($request, [
                'message' => 'Timeclock locked.',
                'redirect' => route('timeclock.unlock'),
            ]);
        }

        if ($action === 'unlock') {
            $this->adminCredentialAccess->unlockTimeclockFor($request, $user);

            return $this->unlockResponse($request, [
                'message' => 'Timeclock unlocked.',
                'redirect' => route('home'),
            ]);
        }

        $this->adminCredentialAccess->unlockAdminFor($request, $user);

        return $this->unlockResponse($request, [
            'message' => 'Admin unlocked.',
            'redirect' => $this->adminCredentialAccess->adminRedirectPath($request, $user),
        ]);
    }

    /**
     * @param  array{message: string, redirect: string}  $payload
     */
    private function unlockResponse(TimeclockUnlockRequest $request, array $payload): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return redirect()->to($payload['redirect']);
    }
}
