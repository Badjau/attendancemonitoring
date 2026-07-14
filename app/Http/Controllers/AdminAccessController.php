<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AdminCredentialAccessService;
use App\Support\AdminAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AdminAccessController extends Controller
{
    public function __construct(protected AdminCredentialAccessService $adminCredentialAccess) {}

    public function showLogin(Request $request): RedirectResponse
    {
        if ($this->hasAdminAccess($request)) {
            return redirect('/admin');
        }

        return redirect()->route('timeclock.unlock');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->adminCredentialAccess->findAdminUser($credentials['username'], $credentials['password']);

        if (! $user) {
            return back()
                ->withErrors(['username' => 'The admin username or password is incorrect.'])
                ->onlyInput('username');
        }

        $this->adminCredentialAccess->unlockAdminFor($request, $user);

        return redirect()->to($this->adminCredentialAccess->adminRedirectPath($request, $user));
    }

    public function showRegister(Request $request): View|RedirectResponse
    {
        if (! $this->canRegister($request)) {
            return redirect()
                ->route('timeclock.unlock')
                ->withErrors(['username' => 'Admin registration is locked. Sign in with an existing admin account.']);
        }

        return view('admin-access.register');
    }

    public function register(Request $request): RedirectResponse
    {
        if (! $this->canRegister($request)) {
            return redirect()
                ->route('timeclock.unlock')
                ->withErrors(['username' => 'Admin registration is locked. Sign in with an existing admin account.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'alpha_dash', 'max:255', Rule::unique(User::class, 'username')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'] ?: "{$data['username']}@admin.local",
            'password' => $data['password'],
            'is_admin' => true,
            'is_it_admin' => true,
        ]);

        $this->adminCredentialAccess->unlockAdminFor($request, $user);

        return redirect($user->is_hr ? '/admin/attendances' : '/admin');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(Auth::guard()->getName());
        Auth::forgetGuards();

        $request->session()->forget([
            'admin_password_unlocked_by',
            'admin_password_unlocked_at',
            'admin_unlocked_by',
            'admin_unlocked_at',
            'timeclock_unlocked_by',
            'timeclock_unlocked_at',
        ]);

        $request->session()->regenerateToken();

        return redirect()->route('timeclock.unlock', ['locked' => 1]);
    }

    private function canRegister(Request $request): bool
    {
        return ! User::query()->where('is_admin', true)->exists()
            || AdminAccess::hasAnyAdminAccess($request);
    }

    private function hasAdminAccess(Request $request): bool
    {
        return AdminAccess::hasAnyAdminAccess($request);
    }
}
