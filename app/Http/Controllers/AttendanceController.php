<?php

namespace App\Http\Controllers;

use App\Enums\Attendance\Status;
use App\Enums\Attendance\Type;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AttendanceController extends Controller
{
    public function __construct(protected AttendanceService $attendanceService)
    {
    }

    public function recordTimeIn(Request $request): RedirectResponse
    {
        try {
            $attendance = $this->attendanceService->recordAttendance($request);
            $attendance->load('employee');
            $employee = $attendance->employee;

            return redirect()->back()
                ->with('success', 'Attendance recorded successfully.')
                ->with('greeting', [
                    'first_name' => $employee->first_name,
                    'is_birthday' => $employee->date_of_birth?->isBirthday() ?? false,
                    'attendance_type' => $request->attendance_type,
                ]);
        } catch (\Exception $e) {
            Log::info('error in recording the attendance: ' . $e->getMessage());

            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
