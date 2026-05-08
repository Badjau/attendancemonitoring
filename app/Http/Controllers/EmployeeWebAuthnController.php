<?php

namespace App\Http\Controllers;

use App\Enums\Attendance\Type;
use App\Models\Employee;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function __construct(
        protected AttendanceService $attendanceService,
    ) {}

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
        // TODO: Transfer this into request class
        $validated = $request->validate([
            'id' => ['required', 'string'],
            'rawId' => ['required', 'string'],
            'response' => ['required', 'array'],
            'response.clientDataJSON' => ['required', 'string'],
            'response.attestationObject' => ['required', 'string'],
            'type' => ['required', 'string'],
            'clientExtensionResults' => ['sometimes', 'array'],
            'authenticatorAttachment' => ['sometimes', 'nullable', 'string'],
            'alias' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $validation = app(AttestationValidator::class)
            ->send(new AttestationValidation($employee, new JsonTransport($validated)))
            ->thenReturn();

        $validation->credential
            ->forceFill(['alias' => $validated['alias'] ?? 'Fingerprint'])
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

    public function recordAttendance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'string'],
            'rawId' => ['required', 'string'],
            'response' => ['required', 'array'],
            'response.authenticatorData' => ['required', 'string'],
            'response.clientDataJSON' => ['required', 'string'],
            'response.signature' => ['required', 'string'],
            'response.userHandle' => ['sometimes', 'nullable'],
            'type' => ['required', 'string'],
            'clientExtensionResults' => ['sometimes', 'array'],
            'authenticatorAttachment' => ['sometimes', 'nullable', 'string'],
            'attendance_type' => ['required', Rule::in([Type::TimeIn->value, Type::TimeOut->value])],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        try {
            $validation = app(AssertionValidator::class)
                ->send(new AssertionValidation(new JsonTransport($validated)))
                ->thenReturn();

            $employee = $validation->credential->authenticatable;

            if (! $employee instanceof Employee) {
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
                    'attendance_type' => $validated['attendance_type'],
                ],
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::info('Fingerprint attendance failed: '.$exception->getMessage());

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }
}
