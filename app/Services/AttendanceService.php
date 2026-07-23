<?php

namespace App\Services;

use App\Enums\Attendance\AttendanceMethod;
use App\Enums\Attendance\Mode;
use App\Enums\Attendance\OvertimeStatus;
use App\Enums\Attendance\Status;
use App\Enums\Attendance\Type;
use App\Models\Attendance;
use App\Models\AttendanceBreak;
use App\Models\Employee;
use App\Models\FaceAttempt;
use App\Models\User;
use App\Support\PasswordVerifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;

class AttendanceService
{
    private const TAP_TIME_IN = 'time-in';

    private const TAP_BREAK_START = 'break-start';

    private const TAP_BREAK_END = 'break-end';

    private const TAP_TIME_OUT = 'time-out';

    public function __construct(
        public Attendance $model,
        protected GeofenceService $geofenceService,
        protected AttendanceScheduleSettings $attendanceScheduleSettings,
    ) {}

    public function recordAttendance(Request $request): Attendance
    {
        if ($request->filled('offline_id')) {
            $existingAttendance = $this->model
                ->where('offline_id', $request->string('offline_id')->toString())
                ->first();

            if ($existingAttendance) {
                return $this->linkFaceAttempt($request, $this->withTapEvent($existingAttendance, $this->latestTapEvent($existingAttendance)));
            }
        }

        $now = $this->attendanceTimestamp($request);
        $employee = $this->findEmployee($request->rfid);
        $attendanceMode = $this->attendanceMode($request);
        $this->finalizePreviousOpenAttendanceDays($employee, $now);

        if ($attendanceMode === Mode::AutoToggle->value) {
            $duplicateAttendance = $this->duplicateScanAttendance($employee, $now, $request);

            if ($duplicateAttendance) {
                return $this->linkFaceAttempt($request, $this->withTapEvent($duplicateAttendance, $this->latestTapEvent($duplicateAttendance)));
            }
        }

        $openAttendance = $this->latestOpenTimeInWithinWindow($employee, $now);

        if (! $openAttendance) {
            $this->ensureEmployeeAuthCooldownHasElapsed($employee, $now);
        }

        $requestedAttendanceType = $request->string('attendance_type')->toString();
        $isManualOverride = in_array($requestedAttendanceType, [Type::TimeIn->value, Type::TimeOut->value], true);
        $attendanceType = $isManualOverride
            ? $requestedAttendanceType
            : $this->inferAttendanceTypeForEmployee($employee, $now, $openAttendance);
        $request->merge([
            'attendance_type' => $attendanceType,
            'attendance_mode' => $attendanceMode,
        ]);

        if (! $isManualOverride && $openAttendance && $attendanceType === Type::TimeIn->value) {
            return $this->linkFaceAttempt($request, $this->handleAutoBreakTap($request, $now, $openAttendance));
        }

        if ($attendanceType === Type::TimeIn->value) {
            return $this->linkFaceAttempt($request, $this->withTapEvent($this->timeIn($request, $now, $employee), self::TAP_TIME_IN));
        }

        if ($attendanceType === Type::TimeOut->value) {
            return $this->linkFaceAttempt($request, $this->withTapEvent($this->timeOut($request, $now, $employee), self::TAP_TIME_OUT));
        }

        throw new \Exception('Invalid attendance type.');
    }

    public function verifyEmployee(Request $request): array
    {
        $attendanceMethod = $request->attendance_method;
        $employee = $attendanceMethod === AttendanceMethod::KEYPAD->value
            ? $this->findEmployeeByPassword($request->employee_id)
            : $this->findEmployeeByIdentifier($request->employee_id);

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee_id' => $attendanceMethod === AttendanceMethod::RFID->value
                    ? 'RFID card not recognized.'
                    : 'Employee is not existing.',
            ]);
        }

        $this->ensureEmployeeCanRecordAttendance($employee);

        $profileUrl = $employee->employeeProfileUrl();
        $faceEnrollmentCount = $employee->faceEmbeddings()->count();

        return [
            'profile_url' => $profileUrl,
            'face_enrollment_count' => $faceEnrollmentCount,
            'face_ready' => $faceEnrollmentCount >= 3,
            'employee' => $employee,
        ];
    }

    private function inferAttendanceTypeForEmployee(Employee $employee, Carbon $now, ?Attendance $openAttendance = null): string
    {
        $openAttendance ??= $this->latestOpenTimeInWithinWindow($employee, $now);

        if (! $openAttendance) {
            return Type::TimeIn->value;
        }

        if ($now->greaterThanOrEqualTo($this->attendanceScheduleSettings->shiftEnd($now))) {
            return Type::TimeOut->value;
        }

        return Type::TimeIn->value;
    }

    private function linkFaceAttempt(Request $request, Attendance $attendance): Attendance
    {
        if (! $request->filled('face_attempt_id')) {
            return $attendance;
        }

        FaceAttempt::query()
            ->whereKey($request->integer('face_attempt_id'))
            ->whereNull('attendance_id')
            ->update([
                'attendance_id' => $attendance->id,
                'fallback_used' => false,
            ]);

        return $attendance;
    }

    private function attendanceTimestamp(Request $request): Carbon
    {
        if (! $request->filled('occurred_at')) {
            return Carbon::now('Asia/Manila');
        }

        return Carbon::parse($request->string('occurred_at')->toString(), 'Asia/Manila')
            ->setTimezone('Asia/Manila');
    }

    private function attendanceWindowStart(Carbon $now): Carbon
    {
        return $now->copy()->subHours(24);
    }

    private function latestOpenTimeInWithinWindow(Employee $employee, Carbon $now): ?Attendance
    {
        return $this->model
            ->where('employee_id', $employee->id)
            ->whereNotNull('time_in')
            ->whereNull('time_out')
            ->whereDate('attendance_date', $now->toDateString())
            ->orderBy('time_in')
            ->orderBy('id')
            ->first();
    }

    private function finalizePreviousOpenAttendanceDays(Employee $employee, Carbon $now): void
    {
        $this->model
            ->where('employee_id', $employee->id)
            ->whereNotNull('time_in')
            ->whereNull('time_out')
            ->whereDate('attendance_date', '<', $now->toDateString())
            ->orderBy('attendance_date')
            ->orderBy('time_in')
            ->get()
            ->each(fn (Attendance $attendance): ?float => $this->closeAttendanceAtConfiguredTimeOut($attendance));
    }

    private function attendanceMethod(Request $request): ?string
    {
        return $request->attendance_method ?: null;
    }

    private function attendanceMode(Request $request): string
    {
        $requestedAttendanceType = $request->string('attendance_type')->toString();

        return in_array($requestedAttendanceType, [Type::TimeIn->value, Type::TimeOut->value], true)
            ? Mode::ManualButton->value
            : Mode::AutoToggle->value;
    }

    private function duplicateScanAttendance(Employee $employee, Carbon $now, Request $request): ?Attendance
    {
        $windowSeconds = min($this->attendanceScheduleSettings->duplicateScanWindowSeconds(), 60);

        if ($windowSeconds <= 0) {
            return null;
        }

        $method = $this->attendanceMethod($request);
        if (! in_array($method, [
            AttendanceMethod::RFID->value,
            AttendanceMethod::FINGERPRINT->value,
            AttendanceMethod::FACE->value,
        ], true)) {
            return null;
        }

        $lastTap = $this->latestTapForDuplicateScan($employee, $now);

        if (! $lastTap || $lastTap['occurred_at']->lt($now->copy()->subSeconds($windowSeconds))) {
            return null;
        }

        return $this->model
            ->where('employee_id', $employee->id)
            ->whereKey($lastTap['attendance_id'])
            ->first();
    }

    /**
     * @return array{attendance_id:int, occurred_at:Carbon}|null
     */
    private function latestTapForDuplicateScan(Employee $employee, Carbon $now): ?array
    {
        $nowValue = $now->format('Y-m-d H:i:s');

        $attendanceEvents = $this->model
            ->where('employee_id', $employee->id)
            ->where(function ($query) use ($nowValue): void {
                $query->where('time_in', '<=', $nowValue)
                    ->orWhere('time_out', '<=', $nowValue);
            })
            ->get(['id', 'time_in', 'time_out'])
            ->flatMap(function (Attendance $attendance): array {
                return collect([
                    $attendance->time_in ? [
                        'attendance_id' => $attendance->id,
                        'occurred_at' => Carbon::parse($attendance->getRawOriginal('time_in') ?? $attendance->time_in, 'Asia/Manila'),
                    ] : null,
                    $attendance->time_out ? [
                        'attendance_id' => $attendance->id,
                        'occurred_at' => Carbon::parse($attendance->getRawOriginal('time_out') ?? $attendance->time_out, 'Asia/Manila'),
                    ] : null,
                ])->filter()->all();
            });

        $breakEvents = AttendanceBreak::query()
            ->where('employee_id', $employee->id)
            ->where(function ($query) use ($nowValue): void {
                $query->where('started_at', '<=', $nowValue)
                    ->orWhere('ended_at', '<=', $nowValue);
            })
            ->get(['attendance_id', 'started_at', 'ended_at'])
            ->flatMap(function (AttendanceBreak $break): array {
                return collect([
                    $break->started_at ? [
                        'attendance_id' => $break->attendance_id,
                        'occurred_at' => Carbon::parse($break->getRawOriginal('started_at') ?? $break->started_at, 'Asia/Manila'),
                    ] : null,
                    $break->ended_at ? [
                        'attendance_id' => $break->attendance_id,
                        'occurred_at' => Carbon::parse($break->getRawOriginal('ended_at') ?? $break->ended_at, 'Asia/Manila'),
                    ] : null,
                ])->filter()->all();
            });

        return $attendanceEvents
            ->merge($breakEvents)
            ->sortByDesc(fn (array $event): int => $event['occurred_at']->getTimestamp())
            ->first();
    }

    private function ensureEmployeeAuthCooldownHasElapsed(Employee $employee, Carbon $now): void
    {
        $cooldownMinutes = $this->attendanceScheduleSettings->sameEmployeeAuthCooldownMinutes();

        if ($cooldownMinutes <= 0) {
            return;
        }

        $lastAuthenticatedAt = $this->latestEmployeeAuthenticatedAt($employee, $now);

        if (! $lastAuthenticatedAt) {
            return;
        }

        $nextAllowedAt = $lastAuthenticatedAt->copy()->addMinutes($cooldownMinutes);

        if ($now->greaterThanOrEqualTo($nextAllowedAt)) {
            return;
        }

        $remainingMinutes = max(1, (int) ceil($now->diffInSeconds($nextAllowedAt) / 60));

        throw ValidationException::withMessages([
            'employee_id' => "Attendance was already recorded recently. Please wait {$remainingMinutes} minute".($remainingMinutes === 1 ? '' : 's').' before trying again.',
        ]);
    }

    private function latestEmployeeAuthenticatedAt(Employee $employee, Carbon $now): ?Carbon
    {
        $nowValue = $now->format('Y-m-d H:i:s');
        $lastTimeIn = $this->model
            ->where('employee_id', $employee->id)
            ->whereNotNull('time_in')
            ->where('time_in', '<=', $nowValue)
            ->max('time_in');
        $lastTimeOut = $this->model
            ->where('employee_id', $employee->id)
            ->whereNotNull('time_out')
            ->where('time_out', '<=', $nowValue)
            ->max('time_out');

        return collect([$lastTimeIn, $lastTimeOut])
            ->filter()
            ->map(fn (string $value): Carbon => Carbon::parse($value, 'Asia/Manila'))
            ->sortByDesc(fn (Carbon $value): int => $value->getTimestamp())
            ->first();
    }

    private function findEmployee(string $employeeId): Employee
    {
        $employee = $this->findEmployeeByIdentifier($employeeId);

        if (! $employee) {
            throw new \Exception('Employee is not existing.');
        }

        $this->ensureEmployeeCanRecordAttendance($employee);

        return $employee;
    }

    private function findEmployeeByIdentifier(?string $identifier): ?Employee
    {
        $candidates = $this->identifierCandidates($identifier);

        if ($candidates === []) {
            return null;
        }

        $primary = $candidates[0];
        $employee = Employee::query()
            ->where('employee_id', $primary)
            ->orWhere('rfid_uid', $primary)
            ->first();

        if ($employee) {
            return $employee;
        }

        return Employee::query()
            ->where(function ($query) use ($candidates): void {
                $query
                    ->whereIn('employee_id', $candidates)
                    ->orWhereIn('rfid_uid', $candidates)
                    ->orWhereIn(DB::raw('TRIM(employee_id)'), $candidates)
                    ->orWhereIn(DB::raw('TRIM(rfid_uid)'), $candidates);
            })
            ->first();
    }

    /**
     * Scanner and face-service identifiers can differ only by zero padding.
     * Keep exact matching first, then add conservative numeric variants.
     *
     * @return array<int, string>
     */
    private function identifierCandidates(?string $identifier): array
    {
        $normalized = trim((string) preg_replace('/[[:cntrl:]]/', '', (string) $identifier));

        if ($normalized === '') {
            return [];
        }

        $candidates = [$normalized];

        if (ctype_digit($normalized)) {
            $unpadded = ltrim($normalized, '0');
            $unpadded = $unpadded === '' ? '0' : $unpadded;
            $candidates[] = $unpadded;

            foreach ([6, 7, 8, 9, 10, 11, 12] as $length) {
                if (strlen($unpadded) <= $length) {
                    $candidates[] = str_pad($unpadded, $length, '0', STR_PAD_LEFT);
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    private function ensureEmployeeCanRecordAttendance(Employee $employee): void
    {
        $hasHrLogin = User::query()
            ->where('employee_id', $employee->id)
            ->where('is_admin', true)
            ->where('is_hr', true)
            ->where('is_it_admin', false)
            ->exists();

        if ($hasHrLogin) {
            return;
        }

        $hasBlockedAdminLogin = User::query()
            ->where('employee_id', $employee->id)
            ->where('is_admin', true)
            ->exists();

        if ($hasBlockedAdminLogin || $employee->role === Employee::ROLE_ADMIN) {
            throw ValidationException::withMessages([
                'employee_id' => 'Admin accounts do not use Time In or Time Out. Use Admin login instead.',
            ]);
        }
    }

    private function timeIn(Request $request, Carbon $now, ?Employee $employee = null): Attendance
    {
        $employee ??= $this->findEmployee($request->rfid);
        $locationData = $this->validateLocation($request, $employee);

        $shiftStart = $this->attendanceScheduleSettings->shiftStart($now);

        $isLate = $now->gt($shiftStart);
        $lateMinutes = $isLate ? $shiftStart->diffInMinutes($now) : 0;

        $attendance = $this->model->create([
            'employee_id' => $employee->id,
            'rfid_uid' => $request->rfid,
            'attendance_type' => Type::TimeIn->value,
            'attendance_method' => $this->attendanceMethod($request),
            'attendance_mode' => $request->attendance_mode ?? Mode::AutoToggle->value,
            'offline_id' => $request->offline_id,
            'auth_cache_revision' => $request->integer('auth_cache_revision') ?: null,
            'cache_state_at_record_time' => $request->string('cache_state_at_record_time')->toString() ?: null,
            'matched_auth_revision' => $request->integer('matched_auth_revision') ?: null,
            'auth_metadata' => $this->authMetadata($request),
            'attendance_date' => $now->toDateString(),
            'time_in' => $now->format('Y-m-d H:i:s'),
            'status' => $isLate ? Status::Late->value : Status::Present->value,
            'is_late' => $isLate,
            'late_minutes' => $lateMinutes,
            'recorded_by' => Auth::id(),
            ...$locationData,
        ]);

        $this->attachAttendanceImage($request, $attendance, 'time-in-image');

        return $attendance->refresh();
    }

    private function timeOut(Request $request, Carbon $now, ?Employee $employee = null): Attendance
    {
        $employee ??= $this->findEmployee($request->rfid);
        $locationData = $this->validateLocation($request, $employee);

        $firstTimeInAttendance = $this->latestOpenTimeInWithinWindow($employee, $now);

        if (! $firstTimeInAttendance) {
            throw new \Exception('Please time in first.');
        }

        $openBreak = $this->openBreak($firstTimeInAttendance);
        if ($openBreak) {
            $this->endBreak($firstTimeInAttendance, $openBreak, $now, true, $request);
            $firstTimeInAttendance->refresh();
        }

        $shiftEnd = $this->attendanceScheduleSettings->shiftEnd($now);
        $timeIn = $this->storedDateTime($firstTimeInAttendance, 'time_in');
        $workedMinutes = max(0, $timeIn->diffInMinutes($now) - (int) $firstTimeInAttendance->break_minutes);

        $isUndertime = $now->lt($shiftEnd);
        $undertimeMinutes = $isUndertime ? $now->diffInMinutes($shiftEnd) : 0;

        $isOvertime = $now->gt($shiftEnd);
        $overtimeMinutes = $isOvertime ? $shiftEnd->diffInMinutes($now) : 0;

        $firstTimeInAttendance->fill([
            'attendance_type' => Type::TimeOut->value,
            'attendance_method' => $this->attendanceMethod($request),
            'attendance_mode' => $request->attendance_mode ?? Mode::AutoToggle->value,
            'auth_cache_revision' => $request->integer('auth_cache_revision') ?: $firstTimeInAttendance->auth_cache_revision,
            'cache_state_at_record_time' => $request->string('cache_state_at_record_time')->toString() ?: $firstTimeInAttendance->cache_state_at_record_time,
            'matched_auth_revision' => $request->integer('matched_auth_revision') ?: $firstTimeInAttendance->matched_auth_revision,
            'auth_metadata' => $this->authMetadata($request) ?? $firstTimeInAttendance->auth_metadata,
            'time_out' => $now->format('Y-m-d H:i:s'),
            'total_hours' => round($workedMinutes / 60, 2),
            'status' => Status::Present->value,
            'is_undertime' => $isUndertime,
            'undertime_minutes' => $undertimeMinutes,
            'is_overtime' => $isOvertime,
            'overtime_minutes' => $overtimeMinutes,
            'overtime_status' => $isOvertime ? OvertimeStatus::Pending->value : null,
            'recorded_by' => Auth::id(),
            ...$locationData,
        ])->save();

        $this->attachAttendanceImage($request, $firstTimeInAttendance, 'time-out-image');

        return $firstTimeInAttendance->refresh();
    }

    private function authMetadata(Request $request): ?array
    {
        $metadata = $request->input('auth_metadata');

        if (is_string($metadata)) {
            $decoded = json_decode($metadata, true);

            return is_array($decoded) ? $decoded : null;
        }

        return is_array($metadata) ? $metadata : null;
    }

    private function handleAutoBreakTap(Request $request, Carbon $now, Attendance $attendance): Attendance
    {
        $openBreak = $this->openBreak($attendance);

        if ($openBreak) {
            return $this->endBreak($attendance, $openBreak, $now, false, $request);
        }

        return $this->startBreak($request, $attendance, $now);
    }

    private function startBreak(Request $request, Attendance $attendance, Carbon $now): Attendance
    {
        $completedBreakCount = $attendance->breaks()->count();

        if ($completedBreakCount >= $this->attendanceScheduleSettings->maxBreaksPerDay()) {
            throw ValidationException::withMessages([
                'employee_id' => 'Maximum breaks for today have already been used.',
            ]);
        }

        $sequenceNumber = $completedBreakCount + 1;

        $break = $attendance->breaks()->create([
            'employee_id' => $attendance->employee_id,
            'attendance_date' => $attendance->attendance_date,
            'sequence_number' => $sequenceNumber,
            'break_policy_type' => $sequenceNumber === 1 ? 'lunch' : 'additional',
            'allowed_minutes' => $sequenceNumber === 1
                ? $this->attendanceScheduleSettings->firstBreakLimitMinutes()
                : $this->attendanceScheduleSettings->additionalBreakLimitMinutes(),
            'started_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $this->attachTapImage($request, $break, 'break-start-images');
        $this->syncBreakSummary($attendance);

        return $this->withTapEvent($attendance->refresh(), self::TAP_BREAK_START);
    }

    private function endBreak(Attendance $attendance, AttendanceBreak $break, Carbon $now, bool $closedByTimeOut = false, ?Request $request = null): Attendance
    {
        $startedAt = Carbon::parse($break->getRawOriginal('started_at') ?? $break->started_at, 'Asia/Manila');
        $durationMinutes = max(0, $startedAt->diffInMinutes($now));
        $exceededMinutes = max(0, $durationMinutes - $break->allowed_minutes);

        $break->fill([
            'ended_at' => $now->format('Y-m-d H:i:s'),
            'duration_minutes' => $durationMinutes,
            'exceeded_minutes' => $exceededMinutes,
            'closed_by_time_out' => $closedByTimeOut,
        ])->save();

        if ($request) {
            $this->attachTapImage($request, $break, 'break-end-images');
        }

        $this->syncBreakSummary($attendance);

        return $this->withTapEvent($attendance->refresh(), $closedByTimeOut ? self::TAP_TIME_OUT : self::TAP_BREAK_END);
    }

    private function openBreak(Attendance $attendance): ?AttendanceBreak
    {
        return $attendance->breaks()
            ->whereNull('ended_at')
            ->latest('started_at')
            ->latest('id')
            ->first();
    }

    private function syncBreakSummary(Attendance $attendance): void
    {
        $summary = $attendance->breaks()
            ->reorder()
            ->selectRaw('count(*) as break_count')
            ->selectRaw('coalesce(sum(case when ended_at is not null then duration_minutes else 0 end), 0) as break_minutes')
            ->selectRaw('coalesce(sum(case when ended_at is not null then exceeded_minutes else 0 end), 0) as break_exceeded_minutes')
            ->first();

        $attendance->forceFill([
            'break_count' => (int) ($summary?->break_count ?? 0),
            'break_minutes' => (int) ($summary?->break_minutes ?? 0),
            'break_exceeded_minutes' => (int) ($summary?->break_exceeded_minutes ?? 0),
        ])->save();
    }

    private function closeAttendanceAtConfiguredTimeOut(Attendance $attendance): ?float
    {
        $timeIn = $this->storedDateTime($attendance, 'time_in');
        $scheduledTimeOut = $this->attendanceScheduleSettings->shiftEnd(
            Carbon::parse($attendance->attendance_date)
        );
        $openBreak = $this->openBreak($attendance);
        if ($openBreak) {
            $this->endBreak($attendance, $openBreak, $scheduledTimeOut, true);
            $attendance->refresh();
        }

        $workedMinutes = max(0, $timeIn->diffInMinutes($scheduledTimeOut, false) - (int) $attendance->break_minutes);
        $totalHours = round($workedMinutes / 60, 2);

        $attendance->fill([
            'attendance_type' => Type::TimeOut->value,
            'attendance_mode' => Mode::AutoToggle->value,
            'time_out' => $scheduledTimeOut->format('Y-m-d H:i:s'),
            'total_hours' => $totalHours,
            'status' => Status::Present->value,
            'is_undertime' => false,
            'undertime_minutes' => 0,
            'is_overtime' => false,
            'overtime_minutes' => 0,
            'overtime_status' => null,
        ])->save();

        $this->syncDailyTotalHours($attendance->employee, Carbon::parse($attendance->attendance_date));

        return $totalHours;
    }

    private function syncDailyTotalHours(Employee $employee, Carbon $date): ?float
    {
        $query = $this->model
            ->whereDate('attendance_date', $date->toDateString())
            ->where('employee_id', $employee->id);

        $firstTimeIn = (clone $query)
            ->whereNotNull('time_in')
            ->min('time_in');

        $lastTimeOut = (clone $query)
            ->whereNotNull('time_out')
            ->max('time_out');

        $totalHours = ($firstTimeIn && $lastTimeOut)
            ? round(max(0, Carbon::parse($firstTimeIn)->diffInMinutes(Carbon::parse($lastTimeOut)) - (int) (clone $query)->sum('break_minutes')) / 60, 2)
            : null;

        (clone $query)->update(['total_hours' => $totalHours]);

        return $totalHours;
    }

    private function markDailyLastTimeInAsTimeOut(Employee $employee, Carbon $date): ?float
    {
        $attendances = $this->model
            ->whereDate('attendance_date', $date->toDateString())
            ->where('employee_id', $employee->id)
            ->whereNotNull('time_in')
            ->get()
            ->sortBy(fn (Attendance $attendance): int => $this->storedDateTime($attendance, 'time_in')->getTimestamp())
            ->values();

        if ($attendances->count() < 2) {
            return null;
        }

        $firstTimeIn = $this->storedDateTime($attendances->first(), 'time_in');
        $lastAttendance = $attendances->last();

        if (! $firstTimeIn || ! $lastAttendance) {
            return null;
        }

        $lastTimeIn = $this->storedDateTime($lastAttendance, 'time_in');
        $totalHours = round($firstTimeIn->diffInMinutes($lastTimeIn) / 60, 2);

        $this->model
            ->whereKey($attendances->pluck('id')->all())
            ->whereKeyNot($lastAttendance->id)
            ->update([
                'attendance_type' => Type::TimeIn->value,
                'time_out' => null,
                'total_hours' => $totalHours,
                'is_overtime' => false,
                'overtime_minutes' => 0,
                'overtime_status' => null,
            ]);

        $lastAttendance->fill([
            'attendance_type' => Type::TimeOut->value,
            'time_out' => $lastTimeIn->format('Y-m-d H:i:s'),
            'total_hours' => $totalHours,
            'status' => Status::Present->value,
            'is_undertime' => false,
            'undertime_minutes' => 0,
            'is_overtime' => false,
            'overtime_minutes' => 0,
            'overtime_status' => null,
        ])->save();

        return $totalHours;
    }

    private function storedDateTime(Attendance $attendance, string $column): Carbon
    {
        return Carbon::parse($attendance->getRawOriginal($column) ?? $attendance->{$column});
    }

    private function withTapEvent(Attendance $attendance, ?string $tapEvent): Attendance
    {
        if ($tapEvent) {
            $attendance->setAttribute('tap_event', $tapEvent);
        }

        return $attendance;
    }

    private function latestTapEvent(Attendance $attendance): ?string
    {
        $events = collect([
            $attendance->time_in ? [
                'event' => self::TAP_TIME_IN,
                'occurred_at' => $this->storedDateTime($attendance, 'time_in'),
            ] : null,
            $attendance->time_out ? [
                'event' => self::TAP_TIME_OUT,
                'occurred_at' => $this->storedDateTime($attendance, 'time_out'),
            ] : null,
        ])->filter();

        $breakEvents = $attendance->breaks()
            ->get()
            ->flatMap(function (AttendanceBreak $break): array {
                return collect([
                    $break->started_at ? [
                        'event' => self::TAP_BREAK_START,
                        'occurred_at' => Carbon::parse($break->getRawOriginal('started_at') ?? $break->started_at, 'Asia/Manila'),
                    ] : null,
                    $break->ended_at ? [
                        'event' => $break->closed_by_time_out ? self::TAP_TIME_OUT : self::TAP_BREAK_END,
                        'occurred_at' => Carbon::parse($break->getRawOriginal('ended_at') ?? $break->ended_at, 'Asia/Manila'),
                    ] : null,
                ])->filter()->all();
            });

        return $events
            ->merge($breakEvents)
            ->sortByDesc(fn (array $event): int => $event['occurred_at']->getTimestamp())
            ->value('event');
    }

    private function attachAttendanceImage(Request $request, Attendance $attendance, string $collection): void
    {
        $this->attachTapImage($request, $attendance, $collection);
    }

    private function attachTapImage(Request $request, HasMedia $model, string $collection): void
    {
        if ($request->hasFile('attendance-image')) {
            $model
                ->addMedia($request->file('attendance-image'))
                ->toMediaCollection($collection);

            return;
        }

        if (! $request->filled('attendance_image')) {
            return;
        }

        $model
            ->addMediaFromBase64($request->string('attendance_image')->toString(), 'image/jpeg', 'image/png')
            ->usingFileName("attendance_{$model->getKey()}_{$collection}.jpg")
            ->toMediaCollection($collection);
    }

    private function validateLocation(Request $request, Employee $employee): array
    {
        $latitude = (float) $request->latitude;
        $longitude = (float) $request->longitude;
        $zones = $employee->activeZones()->get();
        $strictZones = $zones->where('policy', 'strict')->values();

        if ($this->geofenceService->hasStrictZone($zones)) {
            $matchingStrictZone = $this->geofenceService->findMatchingZone($latitude, $longitude, $strictZones);

            if (! $matchingStrictZone) {
                throw ValidationException::withMessages([
                    'location' => 'You are outside your assigned field.',
                ]);
            }

            return [
                'location' => $request->location ?? $matchingStrictZone->name,
                'location_source' => $request->location_source ?? 'live',
                'latitude' => $latitude,
                'longitude' => $longitude,
                'location_status' => 'inside',
                'zone_id' => $matchingStrictZone->id,
            ];
        }

        $matchingZone = $this->geofenceService->findMatchingZone($latitude, $longitude, $zones);

        return [
            'location' => $request->location ?? $matchingZone?->name,
            'location_source' => $request->location_source ?? 'live',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_status' => $matchingZone ? 'inside' : 'outside',
            'zone_id' => $matchingZone?->id,
        ];
    }

    private function findEmployeeByPassword(string $password): ?Employee
    {
        // Select only necessary columns to reduce memory footprint
        return Employee::query()
            ->whereNotNull('password')
            ->select(['id', 'first_name', 'last_name', 'employee_id', 'password'])
            ->get()
            ->first(function (Employee $employee) use ($password): bool {
                return PasswordVerifier::checkHashOrPlainText($password, $employee->password);
            });
    }
}
