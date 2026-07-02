<?php

namespace App\Http\Controllers\Api;

use App\Enums\Attendance\AttendanceMethod;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ZktecoFingerprintTemplate;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ZktecoFingerprintController extends Controller
{
    public function __construct(protected AttendanceService $attendanceService) {}

    public function employees(Request $request): JsonResponse
    {
        $this->authorizeScanner($request);

        $search = trim((string) $request->query('search', ''));

        $employees = Employee::query()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('employee_id', 'like', "%{$search}%")
                        ->orWhere('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%");
                });
            })
            ->orderBy('employee_id')
            ->limit(25)
            ->get(['id', 'employee_id', 'first_name', 'last_name', 'position', 'branch']);

        return response()->json([
            'data' => $employees->map(fn (Employee $employee): array => $this->employeePayload($employee))->values(),
        ]);
    }

    public function fingerprints(Request $request): JsonResponse
    {
        $this->authorizeScanner($request);

        $templates = ZktecoFingerprintTemplate::query()
            ->with('employee:id,employee_id,first_name,last_name,position,branch')
            ->orderBy('employee_id')
            ->orderBy('finger_index')
            ->paginate(500, ['*'], 'page', $request->query('page', 1));

        return response()->json([
            'data' => collect($templates->items())
                ->map(fn (ZktecoFingerprintTemplate $template): array => $this->fingerprintPayload($template))
                ->values(),
            'pagination' => [
                'current_page' => $templates->currentPage(),
                'total' => $templates->total(),
                'last_page' => $templates->lastPage(),
                'per_page' => $templates->perPage(),
            ],
        ]);
    }

    public function fingerprintsManifest(Request $request): JsonResponse
    {
        $this->authorizeScanner($request);

        $latestUpdatedAt = ZktecoFingerprintTemplate::query()->max('updated_at');
        $count = ZktecoFingerprintTemplate::query()->count();

        return response()->json([
            'revision' => hash('sha256', implode('|', [
                $count,
                $latestUpdatedAt ? Carbon::parse($latestUpdatedAt)->toIso8601String() : '',
            ])),
            'count' => $count,
            'last_updated_at' => $latestUpdatedAt
                ? Carbon::parse($latestUpdatedAt)->toIso8601String()
                : null,
        ]);
    }

    public function enroll(Request $request): JsonResponse
    {
        $this->authorizeScanner($request);

        $validated = Validator::make($request->all(), [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'finger_index' => ['nullable', 'integer', 'min:1', 'max:10'],
            'template_base64' => ['required', 'string'],
            'template_size' => ['nullable', 'integer', 'min:1'],
            'device_serial' => ['nullable', 'string', 'max:255'],
            'fingerprint_image_base64' => ['nullable', 'string'],
        ])->validate();

        $fingerIndex = $validated['finger_index'] ?? 1;
        $alreadyRegistered = ZktecoFingerprintTemplate::query()
            ->where('employee_id', $validated['employee_id'])
            ->where('finger_index', $fingerIndex)
            ->exists();

        if ($alreadyRegistered) {
            throw ValidationException::withMessages([
                'finger_index' => 'This finger is already registered. Remove it before registering again.',
            ]);
        }

        $registeredFingerCount = ZktecoFingerprintTemplate::query()
            ->where('employee_id', $validated['employee_id'])
            ->distinct()
            ->count('finger_index');

        if ($registeredFingerCount >= 3) {
            throw ValidationException::withMessages([
                'finger_index' => 'This employee already has 3 registered fingers. Remove one before registering another.',
            ]);
        }

        $template = ZktecoFingerprintTemplate::query()->create([
            'employee_id' => $validated['employee_id'],
            'finger_index' => $fingerIndex,
            'template_base64' => $validated['template_base64'],
            'template_format' => 'zkteco-v10',
            'device_serial' => $validated['device_serial'] ?? null,
            'template_size' => $validated['template_size'] ?? null,
            'fingerprint_image_base64' => $validated['fingerprint_image_base64'] ?? null,
            'enrolled_at' => now(),
        ]);

        $template->load('employee:id,employee_id,first_name,last_name,position,branch');

        return response()->json([
            'message' => 'Fingerprint enrolled successfully.',
            'data' => [
                'id' => $template->id,
                'employee' => $this->employeePayload($template->employee),
                'finger_index' => $template->finger_index,
                'enrolled_at' => $template->enrolled_at?->toIso8601String(),
            ],
        ]);
    }

    public function recordAttendance(Request $request): JsonResponse
    {
        $this->authorizeScanner($request);

        $validated = Validator::make($request->all(), [
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'attendance_type' => ['nullable', 'in:time-in,time-out'],
            'occurred_at' => ['nullable', 'date'],
            'offline_id' => ['nullable', 'string', 'max:255'],
            'device_serial' => ['nullable', 'string', 'max:255'],
            'template_id' => ['nullable', 'integer', 'exists:zkteco_fingerprint_templates,id'],
            'score' => ['nullable', 'integer'],
            'attendance_image' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'location_source' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
        ])->validate();

        $employee = Employee::query()->findOrFail($validated['employee_id']);

        $attendanceRequest = Request::create('/attendance/record-time-in', 'POST', [
            'rfid' => $employee->employee_id,
            'attendance_method' => AttendanceMethod::FINGERPRINT->value,
            'attendance_type' => $validated['attendance_type'] ?? null,
            'occurred_at' => $validated['occurred_at'] ?? null,
            'offline_id' => $validated['offline_id'] ?? 'zkteco-'.$request->string('device_serial')->toString().'-'.now()->timestamp,
            'attendance_image' => $validated['attendance_image'] ?? null,
            'location' => $validated['location'] ?? 'ZKTeco scanner',
            'location_source' => $validated['location_source'] ?? 'scanner',
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
            'remarks' => trim(sprintf(
                'ZKTeco fingerprint scan%s%s',
                isset($validated['template_id']) ? " template #{$validated['template_id']}" : '',
                isset($validated['score']) ? " score {$validated['score']}" : '',
            )),
        ]);

        $attendance = $this->attendanceService->recordAttendance($attendanceRequest);
        $attendance->load('employee:id,employee_id,first_name,last_name,position,branch');

        return response()->json([
            'message' => 'Attendance recorded successfully.',
            'data' => [
                'id' => $attendance->id,
                'attendance_type' => $this->enumValue($attendance->attendance_type),
                'attendance_date' => $attendance->attendance_date?->toDateString(),
                'time_in' => $this->dateTimeValue($attendance->time_in),
                'time_out' => $this->dateTimeValue($attendance->time_out),
                'employee' => $this->employeePayload($attendance->employee),
            ],
        ]);
    }

    private function authorizeScanner(Request $request): void
    {
        $configuredToken = config('services.zkteco.scanner_token');
        $providedToken = $request->bearerToken() ?: $request->header('X-ZKTeco-Token');

        if (! is_string($configuredToken) || $configuredToken === '') {
            throw ValidationException::withMessages([
                'token' => 'Scanner token is not configured.',
            ]);
        }

        if (! is_string($providedToken) || ! hash_equals($configuredToken, $providedToken)) {
            abort(401, 'Invalid scanner token.');
        }
    }

    private function enumValue(mixed $value): mixed
    {
        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    private function dateTimeValue(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        return Carbon::parse($value)->toIso8601String();
    }

    private function employeePayload(?Employee $employee): ?array
    {
        if (! $employee) {
            return null;
        }

        return [
            'id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'name' => $employee->name,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'position' => $employee->position,
            'branch' => $employee->branch,
            'is_birthday' => $employee->date_of_birth?->isBirthday() ?? false,
        ];
    }

    private function fingerprintPayload(ZktecoFingerprintTemplate $template): array
    {
        return [
            'id' => $template->id,
            'employee_id' => $template->employee_id,
            'employee_code' => $template->employee?->employee_id,
            'employee' => $this->employeePayload($template->employee),
            'finger_index' => $template->finger_index,
            'template_base64' => $template->template_base64,
            'template_hash' => hash('sha256', $template->template_base64),
            'template_format' => $template->template_format,
            'template_size' => $template->template_size,
            'device_serial' => $template->device_serial,
            'enrolled_at' => $template->enrolled_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}
