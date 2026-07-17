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

    public function currentTime(): JsonResponse
    {
        $now = now('Asia/Manila');

        return response()->json([
            'iso' => $now->toIso8601String(),
            'timestamp_ms' => $now->getTimestampMs(),
            'timezone' => 'Asia/Manila',
        ]);
    }

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
                    'branch' => $employee->branch,
                    'profile_url' => $verifiedEmployee['profile_url'],
                    'face_ready' => $verifiedEmployee['face_ready'],
                    'face_enrollment_count' => $verifiedEmployee['face_enrollment_count'],
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

    public function recordTimeIn(Request $request): RedirectResponse|JsonResponse
    {
        try {
            $attendance = $this->attendanceService->recordAttendance($request);
            $attendance->load('employee');
            $employee = $attendance->employee;

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Attendance recorded successfully.',
                    'tap_event' => $attendance->tap_event,
                    'greeting' => [
                        'first_name' => $employee->first_name,
                        'is_birthday' => $employee->date_of_birth?->isBirthday() ?? false,
                        'attendance_type' => $attendance->attendance_type->value,
                        'tap_event' => $attendance->tap_event,
                    ],
                    'employee' => [
                        'id' => $employee->id,
                        'employee_id' => $employee->employee_id,
                        'first_name' => $employee->first_name,
                        'last_name' => $employee->last_name,
                        'position' => $employee->position,
                        'branch' => $employee->branch,
                    ],
                ]);
            }

            return redirect()->back()
                ->with('success', 'Attendance recorded successfully.')
                ->with('greeting', [
                    'first_name' => $employee->first_name,
                    'is_birthday' => $employee->date_of_birth?->isBirthday() ?? false,
                    'attendance_type' => $attendance->attendance_type->value,
                    'tap_event' => $attendance->tap_event,
                ]);
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => collect($e->errors())->flatten()->first(),
                    'errors' => $e->errors(),
                ], 422);
            }

            return redirect()->back()->withErrors($e->errors());
        } catch (\Exception $e) {
            Log::info('error in recording the attendance: '.$e->getMessage());

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                ], 422);
            }

            return redirect()->back()->with('error', $e->getMessage());
        }
    }
}
