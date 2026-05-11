<?php

namespace App\Http\Controllers;

use App\Http\Requests\Attendance\VerifyEmployeeRequest;
use App\Models\Employee;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AttendanceController extends Controller
{
    public function __construct(protected AttendanceService $attendanceService) {}

    public function verifyEmployee(VerifyEmployeeRequest $request): JsonResponse
    {
        $employee = $request->attendance_method === 'keypad'
            ? $this->findEmployeeByPassword($request->employee_id)
            : Employee::where('employee_id', $request->employee_id)
                ->orWhere('rfid_uid', $request->employee_id)
                ->first();

        if (! $employee) {
            return response()->json([
                'message' => 'Employee is not existing.',
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

    private function findEmployeeByPassword(string $password): ?Employee
    {
        return Employee::query()
            ->whereNotNull('password')
            ->get()
            ->first(function (Employee $employee) use ($password): bool {
                return Hash::isHashed($employee->password)
                    ? Hash::check($password, $employee->password)
                    : hash_equals($employee->password, $password);
            });
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
