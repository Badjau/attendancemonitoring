<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class KioskAttendanceController extends Controller
{
    public function __construct(protected AttendanceService $attendanceService) {}

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'records' => ['required', 'array'],
            'records.*.offline_uuid' => ['required', 'string', 'max:255'],
            'records.*.employee_id' => ['required', 'string', 'max:255'],
            'records.*.auth_method' => ['required', 'string', 'in:rfid,keypad,face,fingerprint'],
            'records.*.kiosk_id' => ['nullable', 'string', 'max:255'],
            'records.*.local_recorded_at' => ['required', 'date'],
            'records.*.auth_cache_revision' => ['nullable', 'integer', 'min:0'],
            'records.*.cache_state_at_record_time' => ['nullable', 'string', 'in:fresh,stale,expired'],
            'records.*.matched_auth_revision' => ['nullable', 'integer', 'min:0'],
            'records.*.attendance_type' => ['nullable', 'string', 'in:time-in,time-out'],
            'records.*.latitude' => ['nullable', 'numeric'],
            'records.*.longitude' => ['nullable', 'numeric'],
            'records.*.location' => ['nullable', 'string', 'max:255'],
            'records.*.location_source' => ['nullable', 'string', 'max:50'],
            'records.*.attendance_image' => ['nullable', 'string'],
            'records.*.metadata' => ['nullable', 'array'],
        ]);

        $results = collect($validated['records'])
            ->map(fn (array $record): array => $this->syncRecord($record))
            ->values()
            ->all();

        return response()->json([
            'results' => $results,
        ]);
    }

    private function syncRecord(array $record): array
    {
        try {
            $attendance = $this->attendanceService->recordAttendance(new Request([
                'offline_id' => $record['offline_uuid'],
                'occurred_at' => $record['local_recorded_at'],
                'rfid' => $record['employee_id'],
                'attendance_method' => $record['auth_method'],
                'attendance_type' => $record['attendance_type'] ?? null,
                'latitude' => $record['latitude'] ?? null,
                'longitude' => $record['longitude'] ?? null,
                'location' => $record['location'] ?? null,
                'location_source' => $record['location_source'] ?? null,
                'attendance_image' => $record['attendance_image'] ?? null,
                'auth_cache_revision' => $record['auth_cache_revision'] ?? null,
                'cache_state_at_record_time' => $record['cache_state_at_record_time'] ?? null,
                'matched_auth_revision' => $record['matched_auth_revision'] ?? null,
                'auth_metadata' => [
                    ...Arr::wrap($record['metadata'] ?? []),
                    'kiosk_id' => $record['kiosk_id'] ?? null,
                ],
            ]));

            return [
                'offline_uuid' => $record['offline_uuid'],
                'status' => ($record['cache_state_at_record_time'] ?? null) === 'expired'
                    ? 'accepted_with_warning'
                    : 'accepted',
                'attendance_id' => $attendance->id,
                'message' => ($record['cache_state_at_record_time'] ?? null) === 'expired'
                    ? 'Accepted after server-side validation with expired cache metadata.'
                    : 'Accepted.',
            ];
        } catch (ValidationException $exception) {
            return [
                'offline_uuid' => $record['offline_uuid'],
                'status' => 'rejected',
                'message' => collect($exception->errors())->flatten()->first(),
                'errors' => $exception->errors(),
            ];
        } catch (\Throwable $exception) {
            return [
                'offline_uuid' => $record['offline_uuid'],
                'status' => 'needs_review',
                'message' => $exception->getMessage(),
            ];
        }
    }
}
