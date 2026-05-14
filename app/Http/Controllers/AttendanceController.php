<?php

namespace App\Http\Controllers;

use App\Http\Requests\Attendance\VerifyEmployeeRequest;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function __construct(protected AttendanceService $attendanceService) {}

    public function verifyEmployee(VerifyEmployeeRequest $request): JsonResponse
    {
        try {
            $verifiedEmployee = $this->attendanceService->verifyEmployee($request);
            $employee = $verifiedEmployee['employee'];

            return response()->json([
                'employee' => [
                    'id' => $employee->id,
                    'employee_id' => $employee->employee_id,
                    'first_name' => $employee->first_name,
                    'last_name' => $employee->last_name,
                    'position' => $employee->position,
                    'profile_url' => $verifiedEmployee['profile_url'],
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => collect($e->errors())->flatten()->first(),
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
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
            Log::info('error in recording the attendance: '.$e->getMessage());

            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
