<?php

namespace App\Http\Controllers;

use App\Enums\Attendance\Status;
use App\Enums\Attendance\Type;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function __construct(protected AttendanceService $attendanceService)
    {
    }

    public function verifyEmployee(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'string'],
        ]);

        $employee = Employee::where('employee_id', $validated['employee_id'])->first();

        if (!$employee) {
            return response()->json([
                'message' => 'Employee ID is not existing.',
            ], 404);
        }

        $profileUrl = $employee->getFirstMediaUrl('employee-profile');

        if (blank($profileUrl)) {
            return response()->json([
                'message' => 'No registered face found for this employee.',
            ], 422);
        }

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'position' => $employee->position,
                'profile_url' => $profileUrl,
            ],
        ]);
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
        } catch (ValidationException $e) {
            return redirect()->back()->withErrors($e->errors());
        } catch (\Exception $e) {
            Log::info('error in recording the attendance: ' . $e->getMessage());

            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
