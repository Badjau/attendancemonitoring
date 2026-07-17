<?php

namespace Tests\Feature;

use App\Enums\Attendance\AttendanceMethod;
use App\Enums\Attendance\Type;
use App\Models\Attendance;
use App\Models\AttendanceBreak;
use App\Models\Employee;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Joaopaulolndev\FilamentGeneralSettings\Models\GeneralSetting;
use Tests\TestCase;

class AttendanceBreakStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings([
            'time_in_start' => '08:00',
            'time_out_start' => '18:00',
            'max_breaks_per_day' => '2',
            'first_break_limit_minutes' => '60',
            'additional_break_limit_minutes' => '15',
            'duplicate_scan_window_seconds' => '60',
            'same_employee_auth_cooldown_minutes' => '60',
        ]);
    }

    public function test_auto_flow_records_time_in_break_start_break_end_then_time_out(): void
    {
        $employee = $this->employee();

        $timeIn = $this->record($employee, '2026-06-17 07:55:00');
        $breakStart = $this->record($employee, '2026-06-17 12:00:00');
        $breakEnd = $this->record($employee, '2026-06-17 12:45:00');
        $timeOut = $this->record($employee, '2026-06-17 18:05:00');

        $this->assertSame('time-in', $timeIn->tap_event);
        $this->assertSame('break-start', $breakStart->tap_event);
        $this->assertSame('break-end', $breakEnd->tap_event);
        $this->assertSame('time-out', $timeOut->tap_event);
        $this->assertSame($timeIn->id, $timeOut->id);
        $this->assertSame(Type::TimeOut->value, $timeOut->attendance_type->value);
        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame(1, $timeOut->break_count);
        $this->assertSame(45, $timeOut->break_minutes);
        $this->assertSame(0, $timeOut->break_exceeded_minutes);
        $this->assertSame(9.42, (float) $timeOut->total_hours);
    }

    public function test_auto_flow_without_break_times_out_at_or_after_shift_end(): void
    {
        $employee = $this->employee();

        $timeIn = $this->record($employee, '2026-06-17 07:55:00');
        $timeOut = $this->record($employee, '2026-06-17 18:00:00');

        $this->assertSame($timeIn->id, $timeOut->id);
        $this->assertSame('time-out', $timeOut->tap_event);
        $this->assertSame(0, $timeOut->break_count);
        $this->assertNotNull($timeOut->time_out);
    }

    public function test_time_out_closes_open_break_at_same_timestamp(): void
    {
        $employee = $this->employee();

        $this->record($employee, '2026-06-17 07:55:00');
        $this->record($employee, '2026-06-17 17:30:00');
        $timeOut = $this->record($employee, '2026-06-17 18:05:00');

        $break = AttendanceBreak::query()->firstOrFail();

        $this->assertSame('time-out', $timeOut->tap_event);
        $this->assertSame('2026-06-17 18:05:00', Carbon::parse($break->getRawOriginal('ended_at'))->format('Y-m-d H:i:s'));
        $this->assertTrue($break->closed_by_time_out);
        $this->assertSame(35, $break->duration_minutes);
        $this->assertSame(35, $timeOut->break_minutes);
    }

    public function test_first_break_uses_lunch_limit_and_later_breaks_use_additional_limit(): void
    {
        $employee = $this->employee();

        $this->record($employee, '2026-06-17 07:55:00');
        $this->record($employee, '2026-06-17 12:00:00');
        $this->record($employee, '2026-06-17 13:10:00');
        $this->record($employee, '2026-06-17 15:00:00');
        $attendance = $this->record($employee, '2026-06-17 15:20:00');

        $breaks = AttendanceBreak::query()->orderBy('sequence_number')->get();

        $this->assertSame(['lunch', 'additional'], $breaks->pluck('break_policy_type')->all());
        $this->assertSame([60, 15], $breaks->pluck('allowed_minutes')->all());
        $this->assertSame([10, 5], $breaks->pluck('exceeded_minutes')->all());
        $this->assertSame(2, $attendance->break_count);
        $this->assertSame(90, $attendance->break_minutes);
        $this->assertSame(15, $attendance->break_exceeded_minutes);
    }

    public function test_changed_break_limits_affect_new_breaks_only(): void
    {
        $employee = $this->employee();

        $this->record($employee, '2026-06-17 07:55:00');
        $this->record($employee, '2026-06-17 12:00:00');
        $this->settings([
            'time_in_start' => '08:00',
            'time_out_start' => '18:00',
            'max_breaks_per_day' => '2',
            'first_break_limit_minutes' => '30',
            'additional_break_limit_minutes' => '10',
            'duplicate_scan_window_seconds' => '60',
            'same_employee_auth_cooldown_minutes' => '60',
        ]);
        $this->record($employee, '2026-06-17 12:45:00');
        $this->record($employee, '2026-06-17 15:00:00');

        $breaks = AttendanceBreak::query()->orderBy('sequence_number')->get();

        $this->assertSame([60, 10], $breaks->pluck('allowed_minutes')->all());
    }

    public function test_manual_time_out_before_shift_end_bypasses_break_inference(): void
    {
        $employee = $this->employee();

        $this->record($employee, '2026-06-17 07:55:00');
        $timeOut = $this->record($employee, '2026-06-17 12:00:00', [
            'attendance_type' => Type::TimeOut->value,
        ]);

        $this->assertSame('time-out', $timeOut->tap_event);
        $this->assertSame(Type::TimeOut->value, $timeOut->attendance_type->value);
        $this->assertSame(0, AttendanceBreak::query()->count());
    }

    public function test_duplicate_scan_window_does_not_open_or_close_extra_breaks(): void
    {
        $employee = $this->employee();

        $this->record($employee, '2026-06-17 07:55:00');
        $breakStart = $this->record($employee, '2026-06-17 12:00:00');
        $duplicate = $this->record($employee, '2026-06-17 12:00:30');

        $this->assertSame($breakStart->id, $duplicate->id);
        $this->assertSame('break-start', $duplicate->tap_event);
        $this->assertSame(1, AttendanceBreak::query()->count());
        $this->assertNull(AttendanceBreak::query()->first()->ended_at);
    }

    public function test_five_minute_test_taps_are_not_swallowed_by_large_duplicate_window(): void
    {
        $this->settings([
            'time_in_start' => '08:00',
            'time_out_start' => '18:00',
            'max_breaks_per_day' => '2',
            'first_break_limit_minutes' => '60',
            'additional_break_limit_minutes' => '15',
            'duplicate_scan_window_seconds' => '300',
            'same_employee_auth_cooldown_minutes' => '60',
        ]);

        $employee = $this->employee();

        $timeIn = $this->record($employee, '2026-06-17 09:00:00');
        $breakStart = $this->record($employee, '2026-06-17 09:05:00');
        $breakEnd = $this->record($employee, '2026-06-17 09:10:00');

        $this->assertSame($timeIn->id, $breakStart->id);
        $this->assertSame($timeIn->id, $breakEnd->id);
        $this->assertSame('break-start', $breakStart->tap_event);
        $this->assertSame('break-end', $breakEnd->tap_event);
        $this->assertSame(1, $breakStart->break_count);
        $this->assertSame(1, $breakEnd->break_count);
        $this->assertSame(5, $breakEnd->break_minutes);
        $this->assertSame(1, Attendance::query()->count());
    }

    public function test_auto_tap_reuses_existing_same_day_open_workday_when_bad_duplicate_rows_exist(): void
    {
        $employee = $this->employee();

        $workday = Attendance::query()->create([
            'employee_id' => $employee->id,
            'rfid_uid' => $employee->rfid_uid,
            'attendance_type' => Type::TimeIn->value,
            'attendance_method' => AttendanceMethod::FACE->value,
            'attendance_date' => '2026-07-17',
            'time_in' => '2026-07-17 09:00:00',
        ]);

        Attendance::query()->create([
            'employee_id' => $employee->id,
            'rfid_uid' => $employee->rfid_uid,
            'attendance_type' => Type::TimeOut->value,
            'attendance_method' => AttendanceMethod::KEYPAD->value,
            'attendance_date' => '2026-07-17',
            'time_in' => '2026-07-17 09:25:00',
            'time_out' => '2026-07-17 10:00:00',
        ]);

        $breakStart = $this->record($employee, '2026-07-17 10:05:00');

        $this->assertSame($workday->id, $breakStart->id);
        $this->assertSame('break-start', $breakStart->tap_event);
        $this->assertSame(2, Attendance::query()->count());
        $this->assertSame(1, AttendanceBreak::query()->where('attendance_id', $workday->id)->count());
    }

    public function test_controller_response_includes_tap_event(): void
    {
        $employee = $this->employee();

        $this->withoutMiddleware(\App\Http\Middleware\EnsureTimeclockUnlocked::class);

        $response = $this->postJson('/attendance/record-time-in', [
            'rfid' => $employee->rfid_uid,
            'attendance_method' => AttendanceMethod::RFID->value,
            'occurred_at' => '2026-06-17 07:55:00',
            'latitude' => 0,
            'longitude' => 0,
        ]);

        $response->assertOk()
            ->assertJsonPath('tap_event', 'time-in')
            ->assertJsonPath('greeting.tap_event', 'time-in');
    }

    public function test_break_and_time_out_taps_store_photos_on_the_right_records(): void
    {
        Storage::fake('public');

        $employee = $this->employee();

        $timeIn = $this->recordWithImage($employee, '2026-06-17 09:00:00', 'time-in.jpg');
        $breakStart = $this->recordWithImage($employee, '2026-06-17 09:05:00', 'break-start.jpg');
        $breakEnd = $this->recordWithImage($employee, '2026-06-17 09:10:00', 'break-end.jpg');
        $timeOut = $this->recordWithImage($employee, '2026-06-17 18:00:00', 'time-out.jpg');

        $break = AttendanceBreak::query()->firstOrFail();

        $this->assertSame($timeIn->id, $breakStart->id);
        $this->assertSame($timeIn->id, $breakEnd->id);
        $this->assertSame($timeIn->id, $timeOut->id);
        $this->assertTrue($timeOut->hasMedia('time-out-image'));
        $this->assertTrue($break->hasMedia('break-start-images'));
        $this->assertTrue($break->hasMedia('break-end-images'));
    }

    private function employee(): Employee
    {
        $number = Employee::query()->count() + 1;

        return Employee::query()->create([
            'employee_id' => 'EMP-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'rfid_uid' => 'RFID-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'middle_name' => 'Byron',
            'date_of_birth' => '1990-01-01',
            'position' => 'Developer',
            'role' => Employee::ROLE_EMPLOYEE,
        ]);
    }

    private function record(Employee $employee, string $occurredAt, array $payload = []): Attendance
    {
        return app(AttendanceService::class)->recordAttendance(new Request([
            'rfid' => $employee->rfid_uid,
            'attendance_method' => AttendanceMethod::RFID->value,
            'occurred_at' => $occurredAt,
            'latitude' => 0,
            'longitude' => 0,
            ...$payload,
        ]));
    }

    private function recordWithImage(Employee $employee, string $occurredAt, string $fileName, array $payload = []): Attendance
    {
        $request = Request::create('/attendance/record-time-in', 'POST', [
            'rfid' => $employee->rfid_uid,
            'attendance_method' => AttendanceMethod::RFID->value,
            'occurred_at' => $occurredAt,
            'latitude' => 0,
            'longitude' => 0,
            ...$payload,
        ], [], [
            'attendance-image' => UploadedFile::fake()->create($fileName, 10, 'image/jpeg'),
        ]);

        return app(AttendanceService::class)->recordAttendance($request);
    }

    private function settings(array $moreConfigs): void
    {
        GeneralSetting::query()->delete();
        GeneralSetting::query()->create([
            'site_name' => 'TimeClock',
            'more_configs' => $moreConfigs,
        ]);

        $property = new \ReflectionProperty(\App\Services\AttendanceScheduleSettings::class, 'cachedSettings');
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
