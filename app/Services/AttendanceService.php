<?php

namespace App\Services;

use App\Enums\Attendance\Status;
use App\Enums\Attendance\Type;
use App\Enums\Attendance\OvertimeStatus;
use App\Models\Attendance;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AttendanceService
{
    public function __construct(public Attendance $model)
    {
    }

    public function recordAttendance(Request $request): Attendance
    {
        $now = Carbon::now();

        if ($request->attendance_type == Type::TimeIn->value) {
            return $this->timeIn($request, $now);
        }

        if ($request->attendance_type == Type::TimeOut->value) {
            return $this->timeOut($request, $now);
        }

        throw new \Exception('Invalid attendance type.');
    }

    private function findEmployee(string $employeeId): Employee
    {
        $employee = Employee::where('employee_id', $employeeId)->first();

        if (!$employee) {
            throw new \Exception('Employee ID is not existing.');
        }

        return $employee;
    }

    private function timeIn(Request $request, Carbon $now): Attendance
    {
        $employee = $this->findEmployee($request->rfid);

        // Check first if the employee is already time-in to the current day.
        $existingAttendance = $this->model
            ->whereDate('attendance_date', Carbon::today())
            ->where('employee_id', $employee->id)
            ->whereNotNull('time_in')
            ->first();

        if ($existingAttendance) {
            $timeIn = Carbon::parse($existingAttendance->time_in)
                ->timezone('Asia/Manila')
                ->format('h:i A');

            throw new \Exception(
                "Already timed in at {$timeIn}."
            );
        }

        // TODO: Make it the shift start time dynamic
        $shiftStart = Carbon::now()->setTimeFromTimeString('08:00:00');

        $isLate = $now->gt($shiftStart);
        $lateMinutes = $isLate ? $shiftStart->diffInMinutes($now) : 0;

        $attendance = $this->model->create([
            'employee_id' => $employee->id,
            'rfid_uid' => $request->rfid,
            'attendance_date' => $now->toDateString(),
            'time_in' => $now,
            'status' => $isLate ? Status::Late->value : Status::Present->value,
            'is_late' => $isLate,
            'late_minutes' => $lateMinutes,
            'recorded_by' => Auth::id(),
        ]);

        $this->attachAttendanceImage($request, $attendance, 'time-in-image');

        return $attendance;
    }

    private function timeOut(Request $request, Carbon $now): Attendance
    {
        $employee = $this->findEmployee($request->rfid);

        $attendance = $this->model
            ->whereDate('attendance_date', Carbon::today())
            ->where('employee_id', $employee->id)
            ->whereNotNull('time_in')
            ->first();

        if (!$attendance) {
            throw new \Exception('Please time in first.');
        }

        if ($attendance->time_out) {
            $timeOut = Carbon::parse($attendance->time_out)
                ->timezone('Asia/Manila')
                ->format('h:i A');

            throw new \Exception(
                "Already timed out at {$timeOut}."
            );
        }

        // TODO: Make it the shift end time dynamic
        $shiftEnd = Carbon::now()->setTimeFromTimeString('17:00:00');
        $timeIn = Carbon::parse($attendance->time_in);
        $workedMinutes = $timeIn->diffInMinutes($now);

        $isUndertime = $now->lt($shiftEnd);
        $undertimeMinutes = $isUndertime ? $now->diffInMinutes($shiftEnd) : 0;

        $isOvertime = $now->gt($shiftEnd);
        $overtimeMinutes = $isOvertime ? $shiftEnd->diffInMinutes($now) : 0;

        $attendance->update([
            'time_out' => $now,
            'total_hours' => round($workedMinutes / 60, 2),
            'is_undertime' => $isUndertime,
            'undertime_minutes' => $undertimeMinutes,
            'is_overtime' => $isOvertime,
            'overtime_minutes' => $overtimeMinutes,
            'overtime_status' => $isOvertime ? OvertimeStatus::Pending->value : null,
            'recorded_by' => Auth::id(),
        ]);

        $this->attachAttendanceImage($request, $attendance, 'time-out-image');

        return $attendance;
    }

    private function attachAttendanceImage(Request $request, Attendance $attendance, string $collection): void
    {
        if (!$request->hasFile('attendance-image')) {
            return;
        }

        $attendance
            ->addMedia($request->file('attendance-image'))
            ->toMediaCollection($collection);
    }
}
