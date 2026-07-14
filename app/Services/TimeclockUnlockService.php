<?php

namespace App\Services;

use App\Enums\Attendance\AttendanceMethod;
use App\Models\TimeclockAuthorizedUser;
use App\Models\TimeclockUnlockLog;
use App\Models\User;
use App\Support\PasswordVerifier;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TimeclockUnlockService
{
    public function authorizedUserFor(Request $request): ?TimeclockAuthorizedUser
    {
        return match ($request['method']) {
            AttendanceMethod::RFID->value => $this->findByRfidCredential($request->credential),
            AttendanceMethod::FINGERPRINT->value => $this->findByFingerprintCredential($request->credential),
            default => $this->findByPassword($request->credential),
        };
    }

    public function store(Request $request)
    {
        $authorizedUser = $this->authorizedUserFor($request);

        if (! $authorizedUser) {
            throw ValidationException::withMessages([
                'credential' => 'You are not authorized to unlock this timeclock.',
            ]);
        }

        $authorizedUser->loadMissing('employee');

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

                return PasswordVerifier::checkHashOrPlainText($password, $employeePassword);
            });
    }

    private function findByRfidCredential(string $credential): ?TimeclockAuthorizedUser
    {
        $authorizedUser = TimeclockAuthorizedUser::query()
            ->with('employee')
            ->whereHas('employee', fn ($query) => $query->where('rfid_uid', $credential))
            ->where('is_active', true)
            ->first();

        if ($authorizedUser) {
            return $authorizedUser;
        }

        $employee = Employee::query()
            ->where('rfid_uid', $credential)
            ->first();

        return $this->authorizeLinkedAdminEmployee($employee);
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

        $authorizedUser = TimeclockAuthorizedUser::query()
            ->with('employee')
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->whereHas('employee.zktecoFingerprintTemplates', fn ($query) => $query->whereKey($templateId))
            ->first();

        if ($authorizedUser) {
            return $authorizedUser;
        }

        $employee = Employee::query()
            ->whereKey($employeeId)
            ->whereHas('zktecoFingerprintTemplates', fn ($query) => $query->whereKey($templateId))
            ->first();

        return $this->authorizeLinkedAdminEmployee($employee);
    }

    private function authorizeLinkedAdminEmployee(?Employee $employee): ?TimeclockAuthorizedUser
    {
        if (! $employee) {
            return null;
        }

        $hasLinkedAdminUser = User::query()
            ->where('employee_id', $employee->id)
            ->where('is_admin', true)
            ->exists();

        if (! $hasLinkedAdminUser) {
            return null;
        }

        return TimeclockAuthorizedUser::query()->updateOrCreate(
            ['employee_id' => $employee->id],
            ['is_active' => true],
        )->load('employee');
    }
}
