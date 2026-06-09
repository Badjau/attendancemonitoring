<?php

namespace App\Services;

use App\Enums\Attendance\AttendanceMethod;
use App\Models\TimeclockAuthorizedUser;
use App\Models\TimeclockUnlockLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TimeclockUnlockService
{
    public function store(Request $request)
    {
        $authorizedUser = match ($request['method']) {
            AttendanceMethod::RFID->value => TimeclockAuthorizedUser::query()
                ->whereHas('employee', fn ($query) => $query->where('rfid_uid', $request->credential))
                ->where('is_active', true)
                ->first(),
            AttendanceMethod::FINGERPRINT->value => $this->findByFingerprintCredential($request->credential),
            default => $this->findByPassword($request->credential),
        };

        if (! $authorizedUser) {
            throw ValidationException::withMessages([
                'credential' => 'You are not authorized to unlock this timeclock.',
            ]);
        }

        $log = TimeclockUnlockLog::create([
            'timeclock_authorized_user_id' => $authorizedUser->id,
            'method' => $request['method'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'unlocked_at' => now(),
        ]);

        $log->addMediaFromBase64($request->audit_image, 'image/jpeg', 'image/png')
            ->usingFileName("timeclock_unlock_{$log->id}.jpg")
            ->toMediaCollection('unlock-audit-image');

        $request->session()->put([
            'timeclock_unlocked_by' => $authorizedUser->id,
            'timeclock_unlocked_at' => now()->toDateTimeString(),
        ]);
        $request->session()->save();

        return $authorizedUser;
    }

    private function findByPassword(string $password): ?TimeclockAuthorizedUser
    {
        return TimeclockAuthorizedUser::query()
            ->with('employee')
            ->where('is_active', true)
            ->get()
            ->first(function (TimeclockAuthorizedUser $user) use ($password): bool {
                $employeePassword = $user->employee?->password;

                if (! $employeePassword) {
                    return false;
                }

                return Hash::isHashed($employeePassword)
                    ? Hash::check($password, $employeePassword)
                    : hash_equals($employeePassword, $password);
            });
    }

    private function findByFingerprintCredential(string $credential): ?TimeclockAuthorizedUser
    {
        $payload = json_decode($credential, true);

        if (! is_array($payload)) {
            return null;
        }

        $employeeId = (int) ($payload['employee_id'] ?? 0);
        $templateId = (int) ($payload['template_id'] ?? 0);

        if ($employeeId <= 0 || $templateId <= 0) {
            return null;
        }

        return TimeclockAuthorizedUser::query()
            ->with('employee')
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->whereHas('employee.zktecoFingerprintTemplates', fn ($query) => $query->whereKey($templateId))
            ->first();
    }
}
