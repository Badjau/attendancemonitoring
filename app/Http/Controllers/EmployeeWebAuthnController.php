<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeWebAuthn\RecordAttendanceRequest;
use App\Models\Employee;
use App\Models\ZktecoFingerprintTemplate;
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
    public function __construct(protected AttendanceService $attendanceService) {}

    public function registrationOptions(Employee $employee): JsonResponse
    {
        $creation = new AttestationCreation(
            user: $employee,
            residentKey: ResidentKey::Required,
            userVerification: UserVerification::Required,
            uniqueCredentials: false,
        );

        $json = app(AttestationCreator::class)
            ->send($creation)
            ->thenReturn()
            ->json;

        return response()->json($json);
    }

    public function register(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'alias' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $fingerLabel = $this->fingerLabelFromAlias($validated['alias'] ?? null);

        if (
            $fingerLabel &&
            str_ends_with($validated['alias'] ?? '', ' - scan 1') &&
            $this->hasRegisteredFinger($employee, $fingerLabel)
        ) {
            throw ValidationException::withMessages([
                'finger' => "{$fingerLabel} is already registered. Remove it before registering again.",
            ]);
        }

        $validation = app(AttestationValidator::class)
            ->send(new AttestationValidation($employee, new JsonTransport($request->array())))
            ->thenReturn();

        $validation->credential
            ->forceFill(['alias' => $validated['alias'] ?? 'Fingerprint'])
            ->save();

        return response()->json([
            'message' => 'Fingerprint enrolled successfully.',
            'credential_id' => $validation->credential->getKey(),
        ]);
    }

    public function destroyFinger(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'finger' => ['required', 'string', Rule::in($this->fingerLabels())],
        ]);

        $deleted = $employee
            ->webAuthnCredentials()
            ->where('alias', 'like', "{$validated['finger']} fingerprint - scan %")
            ->delete();

        return response()->json([
            'message' => $deleted
                ? "{$validated['finger']} registration removed."
                : "{$validated['finger']} was not registered.",
        ]);
    }

    public function destroyScannerFinger(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'finger_index' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $fingerLabel = $this->scannerFingerLabels()[(int) $validated['finger_index']] ?? 'Fingerprint';

        $deleted = ZktecoFingerprintTemplate::query()
            ->where('employee_id', $employee->id)
            ->where('finger_index', $validated['finger_index'])
            ->delete();

        return response()->json([
            'message' => $deleted
                ? "{$fingerLabel} registration removed."
                : "{$fingerLabel} was not registered.",
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
                    'attendance_type' => $request->attendance_type,
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

    private function hasRegisteredFinger(Employee $employee, string $fingerLabel): bool
    {
        return $employee
            ->webAuthnCredentials()
            ->where('alias', 'like', "{$fingerLabel} fingerprint - scan %")
            ->exists();
    }

    private function fingerLabelFromAlias(?string $alias): ?string
    {
        if (! $alias) {
            return null;
        }

        foreach ($this->fingerLabels() as $fingerLabel) {
            if (str_starts_with($alias, "{$fingerLabel} fingerprint - scan ")) {
                return $fingerLabel;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function fingerLabels(): array
    {
        return [
            'Left thumb',
            'Left index',
            'Left middle',
            'Left ring',
            'Left little',
            'Right thumb',
            'Right index',
            'Right middle',
            'Right ring',
            'Right little',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function scannerFingerLabels(): array
    {
        return [
            1 => 'Left Thumb',
            2 => 'Left Index',
            3 => 'Left Middle',
            4 => 'Left Ring',
            5 => 'Left Little',
            6 => 'Right Thumb',
            7 => 'Right Index',
            8 => 'Right Middle',
            9 => 'Right Ring',
            10 => 'Right Little',
        ];
    }
}
