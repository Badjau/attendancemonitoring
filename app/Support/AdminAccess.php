<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminAccess
{
    public const HR_RESOURCE_KEYS = [
        'announcements',
        'attendances',
        'branches',
        'departments',
        'employees',
        'zones',
    ];

    public static function hasAnyAdminAccess(?Request $request = null): bool
    {
        return self::isItAdmin($request) || self::isHrAdmin($request) || self::isNormalAdmin($request);
    }

    public static function isItAdmin(?Request $request = null): bool
    {
        $adminUser = self::currentAdminUser($request);

        return (bool) $adminUser?->is_admin && (bool) $adminUser?->is_it_admin;
    }

    public static function isHrAdmin(?Request $request = null): bool
    {
        $adminUser = self::currentAdminUser($request);

        return (bool) $adminUser?->is_admin && (bool) $adminUser?->is_hr;
    }

    public static function canAccessResource(string $resourceKey, ?Request $request = null): bool
    {
        if (! self::hasAnyAdminAccess($request)) {
            return false;
        }

        if (! self::isHrAdmin($request)) {
            return true;
        }

        return in_array($resourceKey, self::HR_RESOURCE_KEYS, true);
    }

    public static function isNormalAdmin(?Request $request = null): bool
    {
        $request ??= request();
        $adminUser = self::currentAdminUser($request);

        if ($adminUser?->is_admin) {
            return ! $adminUser->is_hr || $adminUser->is_it_admin;
        }

        $employeeId = $request->session()->get('admin_unlocked_by');

        if (! $employeeId) {
            return false;
        }

        $cacheKey = "admin_access.normal_admin_employee.{$employeeId}";

        if (! $request->attributes->has($cacheKey)) {
            $request->attributes->set($cacheKey, Employee::query()
                ->whereKey($employeeId)
                ->where('role', Employee::ROLE_ADMIN)
                ->exists());
        }

        return (bool) $request->attributes->get($cacheKey);
    }

    private static function currentAdminUser(?Request $request = null): ?User
    {
        $request ??= request();
        $adminUserId = $request->session()->get('admin_password_unlocked_by') ?? Auth::id();

        if (! $adminUserId) {
            return null;
        }

        $cacheKey = "admin_access.current_user.{$adminUserId}";

        if (! $request->attributes->has($cacheKey)) {
            $request->attributes->set($cacheKey, User::query()->find($adminUserId));
        }

        return $request->attributes->get($cacheKey);
    }
}
