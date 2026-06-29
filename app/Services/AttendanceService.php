<?php

namespace App\Services;

use App\Enums\Attendance\AttendanceMethod;
use App\Enums\Attendance\OvertimeStatus;
use App\Enums\Attendance\Status;
use App\Enums\Attendance\Type;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function __construct(
        public Attendance $model,
        protected GeofenceService $geofenceService,
        protected AttendanceScheduleSettings $attendanceScheduleSettings,
    ) {}

    public function recordAttendance(Request $request): Attendance
    {
        if ($request->filled('offline_id')) {
            $existingAttendance = $this->model
                ->where('offline_id', $request->string('offline_id')->toString())
                ->first();

            if ($existingAttendance) {
                return $existingAttendance;
            }
        }

        $now = $request->filled('occurred_at')
            ? Carbon::parse($request->string('occurred_at')->toString(), 'Asia/Manila')
            : Carbon::now('Asia/Manila');
        $employee = $this->findEmployee($request->rfid);
        $this->finalizePreviousAttendanceDays($employee, $now);
        $attendanceType = $this->inferAttendanceTypeForEmployee($employee, $now);
        $request->merge(['attendance_type' => $attendanceType]);

        if ($attendanceType == Type::TimeIn->value) {
            return $this->timeIn($request, $now, $employee);
        }

        if ($attendanceType == Type::TimeOut->value) {
            return $this->timeOut($request, $now, $employee);
        }

        throw new \Exception('Invalid attendance type.');
    }

    public function verifyEmployee(Request $request): array
    {
        $employee = $request->attendance_method === AttendanceMethod::KEYPAD->value
            ? $this->findEmployeeByPassword($request->employee_id)
            : Employee::where('employee_id', $request->employee_id)
                ->orWhere('rfid_uid', $request->employee_id)
                ->first();

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_id' => 'Employee is not existing.',
            ]);
        }

        $this->ensureEmployeeCanRecordAttendance($employee);

        $profileUrl = $employee->getFirstMediaUrl('employee-profile');
        $requiresFaceProfile = in_array($request->attendance_method, [
            AttendanceMethod::KEYPAD->value,
            AttendanceMethod::FACE->value,
        ], true);

        if ($requiresFaceProfile && blank($profileUrl)) {
            throw ValidationException::withMessages([
                'employee_id' => 'No registered face found for this employee.',
            ]);
        }

        return [
            'profile_url' => $profileUrl,
            'employee' => $employee,
        ];
    }

    private function inferAttendanceTypeForEmployee(Employee $employee, Carbon $now): string
    {
        return Type::TimeIn->value;
    }

    private function attendanceWindowStart(Carbon $now): Carbon
    {
        return $now->copy()->subHours(24);
    }

    private function latestOpenTimeInWithinWindow(Employee $employee, Carbon $now): ?Attendance
    {
        return $this->model
            ->where('employee_id', $employee->id)
            ->whereNotNull('time_in')
            ->whereNull('time_out')
            ->whereBetween('time_in', [$this->attendanceWindowStart($now), $now])
            ->orderByDesc('time_in')
            ->orderByDesc('id')
            ->first();
    }

    private function finalizePreviousAttendanceDays(Employee $employee, Carbon $now): void
    {
        $this->model
            ->where('employee_id', $employee->id)
            ->whereNotNull('time_in')
            ->whereDate('attendance_date', '<', $now->toDateString())
            ->distinct()
            ->pluck('attendance_date')
            ->each(fn ($date): ?float => $this->markDailyLastTimeInAsTimeOut($employee, Carbon::parse($date)));
    }

    private function attendanceMethod(Request $request): ?string
    {
        return $request->attendance_method ?: null;
    }

    private function findEmployee(string $employeeId): Employee
    {
        $employee = Employee::where('employee_id', $employeeId)
            ->orWhere('rfid_uid', $employeeId)
            ->first();

        if (! $employee) {
            throw new \Exception('Employee is not existing.');
        }

        $this->ensureEmployeeCanRecordAttendance($employee);

        return $employee;
    }

    private function ensureEmployeeCanRecordAttendance(Employee $employee): void
    {
        $hasHrLogin = User::query()
            ->where('employee_id', $employee->id)
            ->where('is_admin', true)
            ->where('is_hr', true)
            ->where('is_it_admin', false)
            ->exists();

        if ($hasHrLogin) {
            return;
        }

        $hasBlockedAdminLogin = User::query()
            ->where('employee_id', $employee->id)
            ->where('is_admin', true)
            ->exists();

        if ($hasBlockedAdminLogin || $employee->role === Employee::ROLE_ADMIN) {
            throw ValidationException::withMessages([
                'employee_id' => 'Admin accounts do not use Time In or Time Out. Use Admin login instead.',
            ]);
        }
    }

    private function timeIn(Request $request, Carbon $now, ?Employee $employee = null): Attendance
    {
        $employee ??= $this->findEmployee($request->rfid);
        $locationData = $this->validateLocation($request, $employee);

        // TODO: Make it the shift start time dynamic
        $shiftStart = $now->copy()->setTimeFromTimeString('08:00:00');

        $isLate = $now->gt($shiftStart);
        $lateMinutes = $isLate ? $shiftStart->diffInMinutes($now) : 0;

        $attendance = $this->model->create([
            'employee_id' => $employee->id,
            'rfid_uid' => $request->rfid,
            'attendance_type' => Type::TimeIn->value,
            'attendance_method' => $this->attendanceMethod($request),
            'offline_id' => $request->offline_id,
            'attendance_date' => $now->toDateString(),
            'time_in' => $now->format('Y-m-d H:i:s'),
            'status' => $isLate ? Status::Late->value : Status::Present->value,
            'is_late' => $isLate,
            'late_minutes' => $lateMinutes,
            'recorded_by' => Auth::id(),
            ...$locationData,
        ]);

        $this->attachAttendanceImage($request, $attendance, 'time-in-image');
        $this->markDailyLastTimeInAsTimeOut($employee, $now);

        return $attendance->refresh();
    }

    private function timeOut(Request $request, Carbon $now, ?Employee $employee = null): Attendance
    {
        $employee ??= $this->findEmployee($request->rfid);
        $locationData = $this->validateLocation($request, $employee);

        $firstTimeInAttendance = $this->latestOpenTimeInWithinWindow($employee, $now);

        if (! $firstTimeInAttendance) {
            throw new \Exception('Please time in first.');
        }

        // TODO: Make it the shift end time dynamic
        $shiftEnd = $now->copy()->setTimeFromTimeString('18:00:00');
        $timeIn = $this->storedDateTime($firstTimeInAttendance, 'time_in');
        $workedMinutes = $timeIn->diffInMinutes($now);

        $isUndertime = $now->lt($shiftEnd);
        $undertimeMinutes = $isUndertime ? $now->diffInMinutes($shiftEnd) : 0;

        $isOvertime = $now->gt($shiftEnd);
        $overtimeMinutes = $isOvertime ? $shiftEnd->diffInMinutes($now) : 0;

        $firstTimeInAttendance->fill([
            'attendance_type' => Type::TimeOut->value,
            'attendance_method' => $this->attendanceMethod($request),
            'time_out' => $now->format('Y-m-d H:i:s'),
            'total_hours' => round($workedMinutes / 60, 2),
            'status' => Status::Present->value,
            'is_undertime' => $isUndertime,
            'undertime_minutes' => $undertimeMinutes,
            'is_overtime' => $isOvertime,
            'overtime_minutes' => $overtimeMinutes,
            'overtime_status' => $isOvertime ? OvertimeStatus::Pending->value : null,
            'recorded_by' => Auth::id(),
            ...$locationData,
        ])->save();

        $this->attachAttendanceImage($request, $firstTimeInAttendance, 'time-out-image');

        return $firstTimeInAttendance->refresh();
    }

    private function syncDailyTotalHours(Employee $employee, Carbon $date): ?float
    {
        $query = $this->model
            ->whereDate('attendance_date', $date->toDateString())
            ->where('employee_id', $employee->id);

        $firstTimeIn = (clone $query)
            ->whereNotNull('time_in')
            ->min('time_in');

        $lastTimeOut = (clone $query)
            ->whereNotNull('time_out')
            ->max('time_out');

        $totalHours = ($firstTimeIn && $lastTimeOut)
            ? round(Carbon::parse($firstTimeIn)->diffInMinutes(Carbon::parse($lastTimeOut)) / 60, 2)
            : null;

        (clone $query)->update(['total_hours' => $totalHours]);

        return $totalHours;
    }

    private function markDailyLastTimeInAsTimeOut(Employee $employee, Carbon $date): ?float
    {
        $attendances = $this->model
            ->whereDate('attendance_date', $date->toDateString())
            ->where('employee_id', $employee->id)
            ->whereNotNull('time_in')
            ->get()
            ->sortBy(fn (Attendance $attendance): int => $this->storedDateTime($attendance, 'time_in')->getTimestamp())
            ->values();

        if ($attendances->count() < 2) {
            return null;
        }

        $firstTimeIn = $this->storedDateTime($attendances->first(), 'time_in');
        $lastAttendance = $attendances->last();

        if (! $firstTimeIn || ! $lastAttendance) {
            return null;
        }

        $lastTimeIn = $this->storedDateTime($lastAttendance, 'time_in');
        $totalHours = round($firstTimeIn->diffInMinutes($lastTimeIn) / 60, 2);

        $this->model
            ->whereKey($attendances->pluck('id')->all())
            ->whereKeyNot($lastAttendance->id)
            ->update([
            'attendance_type' => Type::TimeIn->value,
            'time_out' => null,
            'total_hours' => $totalHours,
            'is_overtime' => false,
            'overtime_minutes' => 0,
            'overtime_status' => null,
        ]);

        $lastAttendance->fill([
            'attendance_type' => Type::TimeOut->value,
            'time_out' => $lastTimeIn->format('Y-m-d H:i:s'),
            'total_hours' => $totalHours,
            'status' => Status::Present->value,
            'is_undertime' => false,
            'undertime_minutes' => 0,
            'is_overtime' => false,
            'overtime_minutes' => 0,
            'overtime_status' => null,
        ])->save();

        return $totalHours;
    }

    private function storedDateTime(Attendance $attendance, string $column): Carbon
    {
        return Carbon::parse($attendance->getRawOriginal($column) ?? $attendance->{$column});
    }

    private function attachAttendanceImage(Request $request, Attendance $attendance, string $collection): void
    {
        if ($request->hasFile('attendance-image')) {
            $attendance
                ->addMedia($request->file('attendance-image'))
                ->toMediaCollection($collection);

            return;
        }

        if (! $request->filled('attendance_image')) {
            return;
        }

        $attendance
            ->addMediaFromBase64($request->string('attendance_image')->toString(), 'image/jpeg', 'image/png')
            ->usingFileName("attendance_{$attendance->id}.jpg")
            ->toMediaCollection($collection);
    }

    private function validateLocation(Request $request, Employee $employee): array
    {
        $latitude = (float) $request->latitude;
        $longitude = (float) $request->longitude;
        $zones = $employee->activeZones()->get();
        $strictZones = $zones->where('policy', 'strict')->values();

        if ($this->geofenceService->hasStrictZone($zones)) {
            $matchingStrictZone = $this->geofenceService->findMatchingZone($latitude, $longitude, $strictZones);

            if (! $matchingStrictZone) {
                throw ValidationException::withMessages([
                    'location' => 'You are outside your assigned field.',
                ]);
            }

            return [
                'location' => $request->location ?? $matchingStrictZone->name,
                'location_source' => $request->location_source ?? 'live',
                'latitude' => $latitude,
                'longitude' => $longitude,
                'location_status' => 'inside',
                'zone_id' => $matchingStrictZone->id,
            ];
        }

        $matchingZone = $this->geofenceService->findMatchingZone($latitude, $longitude, $zones);

        return [
            'location' => $request->location ?? $matchingZone?->name,
            'location_source' => $request->location_source ?? 'live',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_status' => $matchingZone ? 'inside' : 'outside',
            'zone_id' => $matchingZone?->id,
        ];
    }

    private function findEmployeeByPassword(string $password): ?Employee
    {
        // Select only necessary columns to reduce memory footprint
        return Employee::query()
            ->whereNotNull('password')
            ->select(['id', 'first_name', 'last_name', 'employee_id', 'password'])
            ->get()
            ->first(function (Employee $employee) use ($password): bool {
                return Hash::isHashed($employee->password)
                    ? Hash::check($password, $employee->password)
                    : hash_equals($employee->password, $password);
            });
    }
}
