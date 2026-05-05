<?php

namespace App\Services;

use App\Enums\Attendance\Status;
use App\Enums\Attendance\Type;
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

    public function recordAttendance(Request $request)
    {
        if ($request->attendance_type == Type::TimeIn->value) {
            $now = Carbon::now();
            $this->timeIn($request, $now);
        }
    }

    private function timeIn(Request $request, $now): Attendance
    {
        // Check first if the employee is already time-in to the current day.
        $existingAttendance = $this->model
            ->whereDate('attendance_date', Carbon::today())
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

        $employee = Employee::where('employee_id', $request->rfid)->firstOrFail();
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

        // Check if there's image captured together with the request. If yes, insert it.
        if ($request->hasFile('attendance-image')) {
            $attendance->addMedia($request->file('attendance-image'))->toMediaCollection('attendance-image');
        }

        return $attendance;
    }
}
