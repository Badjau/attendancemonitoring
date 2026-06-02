<?php

namespace App\Services;

use App\Enums\Attendance\AttendanceMethod;
use App\Enums\Attendance\OvertimeStatus;
use App\Enums\Attendance\Status;
use App\Enums\Attendance\Type;
use App\Models\Attendance;
use App\Models\Employee;
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
            ? Carbon::parse($request->string('occurred_at')->toString())->setTimezone('Asia/Manila')
            : Carbon::now('Asia/Manila');
        $attendanceType = $request->attendance_type ?: $this->inferAttendanceType($now);
        $request->merge(['attendance_type' => $attendanceType]);

        if ($attendanceType == Type::TimeIn->value) {
            return $this->timeIn($request, $now);
        }

        if ($attendanceType == Type::TimeOut->value) {
            return $this->timeOut($request, $now);
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

        $profileUrl = $employee->getFirstMediaUrl('employee-profile');

        if (blank($profileUrl)) {
            throw ValidationException::withMessages([
                'employee_id' => 'No registered face found for this employee.',
            ]);
        }

        return [
            'profile_url' => $profileUrl,
            'employee' => $employee,
        ];
    }

    private function inferAttendanceType(Carbon $now): string
    {
        return $this->attendanceScheduleSettings->inferAttendanceType($now);
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

        return $employee;
    }

    private function timeIn(Request $request, Carbon $now): Attendance
    {
        $employee = $this->findEmployee($request->rfid);
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
            'time_in' => $now,
            'status' => $isLate ? Status::Late->value : Status::Present->value,
            'is_late' => $isLate,
            'late_minutes' => $lateMinutes,
            'recorded_by' => Auth::id(),
            ...$locationData,
        ]);

        $this->attachAttendanceImage($request, $attendance, 'time-in-image');
        $this->syncDailyTotalHours($employee, $now);

        return $attendance;
    }

    private function timeOut(Request $request, Carbon $now): Attendance
    {
        $employee = $this->findEmployee($request->rfid);
        $locationData = $this->validateLocation($request, $employee);

        $firstTimeInAttendance = $this->model
            ->whereDate('attendance_date', $now->toDateString())
            ->where('employee_id', $employee->id)
            ->whereNotNull('time_in')
            ->where('time_in', '<=', $now)
            ->orderBy('time_in')
            ->orderBy('id')
            ->first();

        if (! $firstTimeInAttendance) {
            throw new \Exception('Please time in first.');
        }

        // TODO: Make it the shift end time dynamic
        $shiftEnd = $now->copy()->setTimeFromTimeString('18:00:00');
        $timeIn = Carbon::parse($firstTimeInAttendance->time_in);
        $workedMinutes = $timeIn->diffInMinutes($now);

        $isUndertime = $now->lt($shiftEnd);
        $undertimeMinutes = $isUndertime ? $now->diffInMinutes($shiftEnd) : 0;

        $isOvertime = $now->gt($shiftEnd);
        $overtimeMinutes = $isOvertime ? $shiftEnd->diffInMinutes($now) : 0;

        $attendance = $this->model->create([
            'employee_id' => $employee->id,
            'rfid_uid' => $request->rfid,
            'attendance_type' => Type::TimeOut->value,
            'attendance_method' => $this->attendanceMethod($request),
            'offline_id' => $request->offline_id,
            'attendance_date' => $now->toDateString(),
            'time_out' => $now,
            'total_hours' => round($workedMinutes / 60, 2),
            'status' => Status::Present->value,
            'is_undertime' => $isUndertime,
            'undertime_minutes' => $undertimeMinutes,
            'is_overtime' => $isOvertime,
            'overtime_minutes' => $overtimeMinutes,
            'overtime_status' => $isOvertime ? OvertimeStatus::Pending->value : null,
            'recorded_by' => Auth::id(),
            ...$locationData,
        ]);

        $this->attachAttendanceImage($request, $attendance, 'time-out-image');
        $attendance->total_hours = $this->syncDailyTotalHours($employee, $now);

        return $attendance;
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
