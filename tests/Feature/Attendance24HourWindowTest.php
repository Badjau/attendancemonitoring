<?php

namespace Tests\Feature;

use App\Enums\Attendance\AttendanceMethod;
use App\Enums\Attendance\Mode;
use App\Enums\Attendance\Type;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Joaopaulolndev\FilamentGeneralSettings\Models\GeneralSetting;
use Tests\TestCase;

class Attendance24HourWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        GeneralSetting::query()->create([
            'site_name' => 'TimeClock',
            'more_configs' => [
                'time_in_start' => '08:00',
                'time_out_start' => '18:00',
                'duplicate_scan_window_seconds' => '60',
                'show_face_attendance_button' => false,
            ],
        ]);
    }

    public function test_auto_toggle_records_time_in_then_time_out_for_the_same_employee(): void
    {
        $employee = $this->employee();

        $timeIn = $this->record($employee, [
            'attendance_method' => AttendanceMethod::RFID->value,
            'occurred_at' => '2026-06-17 07:55:00',
        ]);

        $timeOut = $this->record($employee, [
            'attendance_method' => AttendanceMethod::RFID->value,
            'occurred_at' => '2026-06-17 18:05:00',
        ]);

        $this->assertSame($timeIn->id, $timeOut->id);
        $this->assertSame(Type::TimeOut->value, $timeOut->attendance_type->value);
        $this->assertSame(Mode::AutoToggle->value, $timeOut->attendance_mode->value);
        $this->assertSame('2026-06-17 07:55:00', Carbon::parse($timeOut->getRawOriginal('time_in'))->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-17 18:05:00', Carbon::parse($timeOut->getRawOriginal('time_out'))->format('Y-m-d H:i:s'));
        $this->assertSame(1, Attendance::query()->count());
    }

    public function test_manual_time_out_without_a_time_in_is_rejected(): void
    {
        $this->expectExceptionMessage('Please time in first.');

        $this->record($this->employee(), [
            'attendance_method' => AttendanceMethod::KEYPAD->value,
            'attendance_type' => Type::TimeOut->value,
            'occurred_at' => '2026-06-17 18:05:00',
        ]);
    }

    public function test_manual_button_selection_overrides_auto_toggle_mode(): void
    {
        $attendance = $this->record($this->employee(), [
            'attendance_method' => AttendanceMethod::KEYPAD->value,
            'attendance_type' => Type::TimeIn->value,
            'occurred_at' => '2026-06-17 18:05:00',
        ]);

        $this->assertSame(Type::TimeIn->value, $attendance->attendance_type->value);
        $this->assertSame(Mode::ManualButton->value, $attendance->attendance_mode->value);
    }

    public function test_duplicate_auto_scan_within_configured_window_returns_existing_attendance(): void
    {
        $employee = $this->employee();

        $firstScan = $this->record($employee, [
            'attendance_method' => AttendanceMethod::RFID->value,
            'occurred_at' => '2026-06-17 07:55:00',
        ]);

        $duplicateScan = $this->record($employee, [
            'attendance_method' => AttendanceMethod::RFID->value,
            'occurred_at' => '2026-06-17 07:55:30',
        ]);

        $this->assertSame($firstScan->id, $duplicateScan->id);
        $this->assertSame(Type::TimeIn->value, $duplicateScan->attendance_type->value);
        $this->assertNull($duplicateScan->time_out);
        $this->assertSame(1, Attendance::query()->count());
    }

    public function test_next_day_scan_closes_previous_open_time_in_at_configured_time_out_then_records_today_time_in(): void
    {
        $employee = $this->employee();

        $previousTimeIn = Attendance::query()->create([
            'employee_id' => $employee->id,
            'rfid_uid' => $employee->rfid_uid,
            'attendance_type' => Type::TimeIn->value,
            'attendance_method' => AttendanceMethod::RFID->value,
            'attendance_mode' => Mode::AutoToggle->value,
            'attendance_date' => '2026-06-17',
            'time_in' => '2026-06-17 07:55:00',
        ]);

        $attendance = $this->record($employee, [
            'attendance_method' => AttendanceMethod::RFID->value,
            'occurred_at' => '2026-06-18 07:55:00',
        ]);

        $previousTimeIn->refresh();

        $this->assertSame(Type::TimeOut->value, $previousTimeIn->attendance_type->value);
        $this->assertSame('2026-06-17 18:00:00', Carbon::parse($previousTimeIn->getRawOriginal('time_out'))->format('Y-m-d H:i:s'));
        $this->assertSame(10.08, (float) $previousTimeIn->total_hours);

        $this->assertNotSame($previousTimeIn->id, $attendance->id);
        $this->assertSame(Type::TimeIn->value, $attendance->attendance_type->value);
        $this->assertSame('2026-06-18 07:55:00', Carbon::parse($attendance->getRawOriginal('time_in'))->format('Y-m-d H:i:s'));
        $this->assertNull($attendance->time_out);
    }

    public function test_iso_utc_occurred_at_is_saved_as_manila_time(): void
    {
        $attendance = $this->record($this->employee(), [
            'attendance_method' => AttendanceMethod::RFID->value,
            'occurred_at' => '2026-06-17T09:53:00.000Z',
        ]);

        $this->assertSame('2026-06-17', $attendance->attendance_date->format('Y-m-d'));
        $this->assertSame('2026-06-17 17:53:00', Carbon::parse($attendance->getRawOriginal('time_in'))->format('Y-m-d H:i:s'));
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

    private function record(Employee $employee, array $payload): Attendance
    {
        return app(AttendanceService::class)->recordAttendance(new Request([
            'rfid' => $employee->rfid_uid,
            'latitude' => 0,
            'longitude' => 0,
            ...$payload,
        ]));
    }
}
