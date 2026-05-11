<?php

namespace App\Services;

use App\Enums\Attendance\OvertimeStatus;
use App\Enums\Attendance\Status;
use App\Enums\Attendance\Type;
use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function __construct(
        public Attendance $model,
        protected GeofenceService $geofenceService,
    ) {}

    public function recordAttendance(Request $request): Attendance
    {
        $now = Carbon::now('Asia/Manila');
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

    private function inferAttendanceType(Carbon $now): string
    {
        $minutesFromMidnight = ($now->hour * 60) + $now->minute;

        return $minutesFromMidnight <= (16 * 60)
            ? Type::TimeIn->value
            : Type::TimeOut->value;
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
            'attendance_date' => $now->toDateString(),
            'time_in' => $now,
            'status' => $isLate ? Status::Late->value : Status::Present->value,
            'is_late' => $isLate,
            'late_minutes' => $lateMinutes,
            'recorded_by' => Auth::id(),
            ...$locationData,
        ]);

        $this->attachAttendanceImage($request, $attendance, 'time-in-image');

        return $attendance;
    }

    private function timeOut(Request $request, Carbon $now): Attendance
    {
        $employee = $this->findEmployee($request->rfid);
        $locationData = $this->validateLocation($request, $employee);

        $latestTimeInAttendance = $this->model
            ->whereDate('attendance_date', $now->toDateString())
            ->where('employee_id', $employee->id)
            ->whereNotNull('time_in')
            ->where('time_in', '<=', $now)
            ->orderByDesc('time_in')
            ->orderByDesc('id')
            ->first();

        if (! $latestTimeInAttendance) {
            throw new \Exception('Please time in first.');
        }

        // TODO: Make it the shift end time dynamic
        $shiftEnd = $now->copy()->setTimeFromTimeString('17:00:00');
        $timeIn = Carbon::parse($latestTimeInAttendance->time_in);
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

        return $attendance;
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
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'location' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $latitude = (float) $validated['latitude'];
        $longitude = (float) $validated['longitude'];
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
                'location' => $validated['location'] ?? $matchingStrictZone->name,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'location_status' => 'inside',
                'zone_id' => $matchingStrictZone->id,
            ];
        }

        $matchingZone = $this->geofenceService->findMatchingZone($latitude, $longitude, $zones);

        return [
            'location' => $validated['location'] ?? $matchingZone?->name,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_status' => $matchingZone ? 'inside' : 'outside',
            'zone_id' => $matchingZone?->id,
        ];
    }
}
