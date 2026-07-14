<?php

namespace App\Services;

use App\Models\User;
use App\Support\PasswordVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminCredentialAccessService
{
    public function findAdminUser(string $username, string $password): ?User
    {
        $user = User::query()
            ->where('is_admin', true)
            ->where(function ($query) use ($username) {
                $query
                    ->where('username', $username)
                    ->orWhere('email', $username);
            })
            ->first();

        if (! $user || ! PasswordVerifier::check($password, $user->password)) {
            return null;
        }

        return $user;
    }

    public function unlockAdminFor(Request $request, User $user): void
    {
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
    }

    public function unlockTimeclockFor(Request $request, User $user): void
    {
        $request->session()->forget(Auth::guard()->getName());
        Auth::forgetGuards();

        $request->session()->regenerate();
        $request->session()->forget([
            'admin_unlocked_by',
            'admin_unlocked_at',
            'admin_password_unlocked_by',
            'admin_password_unlocked_at',
        ]);
        $request->session()->put([
            'timeclock_unlocked_by' => "admin:{$user->id}",
            'timeclock_unlocked_at' => now()->toDateTimeString(),
        ]);
        $request->session()->save();
    }

    public function adminRedirectPath(Request $request, User $user): string
    {
        $fallback = $user->is_hr ? '/admin/attendances' : '/admin';
        $intended = $request->session()->pull('url.intended', $fallback);
        $path = parse_url($intended, PHP_URL_PATH) ?: $fallback;

        if (in_array($path, ['/admin/login', '/admin/register', '/unlock'], true)) {
            return $fallback;
        }

        return $intended;
    }
}
