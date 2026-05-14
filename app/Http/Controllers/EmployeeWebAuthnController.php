<?php

namespace App\Http\Controllers;

use App\Enums\Attendance\Type;
use App\Http\Requests\EmployeeWebAuthn\RecordAttendanceRequest;
use App\Models\Employee;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laragear\WebAuthn\Assertion\Creator\AssertionCreation;
use Laragear\WebAuthn\Assertion\Creator\AssertionCreator;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidation;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidator;
use Laragear\WebAuthn\Attestation\Creator\AttestationCreation;
use Laragear\WebAuthn\Attestation\Creator\AttestationCreator;
use Laragear\WebAuthn\Attestation\Validator\AttestationValidation;
use Laragear\WebAuthn\Attestation\Validator\AttestationValidator;
use Laragear\WebAuthn\Enums\ResidentKey;
use Laragear\WebAuthn\Enums\UserVerification;
use Laragear\WebAuthn\JsonTransport;

class EmployeeWebAuthnController extends Controller
{
    public function __construct(protected AttendanceService $attendanceService)
    {
    }

    public function registrationOptions(Employee $employee): JsonResponse
    {
        $creation = new AttestationCreation(
            user: $employee,
            residentKey: ResidentKey::Required,
            userVerification: UserVerification::Required,
        );

        $json = app(AttestationCreator::class)
            ->send($creation)
            ->thenReturn()
            ->json;

        return response()->json($json);
    }

    public function register(Request $request, Employee $employee): JsonResponse
    {
        $validation = app(AttestationValidator::class)
            ->send(new AttestationValidation($employee, new JsonTransport($request->array())))
            ->thenReturn();

        $validation->credential
            ->forceFill(['alias' => $request->alias ?? 'Fingerprint'])
            ->save();

        return response()->json([
            'message' => 'Fingerprint enrolled successfully.',
            'credential_id' => $validation->credential->getKey(),
        ]);
    }

    public function assertionOptions(): JsonResponse
    {
        $creation = new AssertionCreation(
            userVerification: UserVerification::Required,
        );

        $json = app(AssertionCreator::class)
            ->send($creation)
            ->thenReturn()
            ->json;

        return response()->json($json);
    }

    public function recordAttendance(RecordAttendanceRequest $request): JsonResponse
    {
        try {
            $credentialPayload = Arr::only($request->array(), [
                'id',
                'rawId',
                'response',
                'type',
                'clientExtensionResults',
                'authenticatorAttachment',
            ]);

            $validation = app(AssertionValidator::class)
                ->send(new AssertionValidation(new JsonTransport($credentialPayload)))
                ->thenReturn();

            $employee = $validation->credential->authenticatable;

            if (!$employee instanceof Employee) {
                throw ValidationException::withMessages([
                    'fingerprint' => 'Fingerprint credential is not assigned to an employee.',
                ]);
            }

            $request->merge([
                'rfid' => $employee->employee_id,
                'attendance_method' => 'fingerprint',
            ]);

            $this->attendanceService->recordAttendance($request);

            return response()->json([
                'message' => 'Attendance recorded successfully.',
                'employee' => [
                    'id' => $employee->id,
                    'employee_id' => $employee->employee_id,
                    'first_name' => $employee->first_name,
                    'last_name' => $employee->last_name,
                    'position' => $employee->position,
                ],
                'greeting' => [
                    'first_name' => $employee->first_name,
                    'is_birthday' => $employee->date_of_birth?->isBirthday() ?? false,
                    'attendance_type' => $request->attendance_type,
                ],
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::info('Fingerprint attendance failed: ' . $exception->getMessage());

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
