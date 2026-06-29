<?php

namespace Tests\Feature;

use App\Enums\Attendance\Type;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\AttendanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class Attendance24HourWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_time_in_of_the_day_is_marked_as_the_time_out(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'EMP-001',
            'rfid_uid' => 'RFID-001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'middle_name' => 'Byron',
            'date_of_birth' => '1990-01-01',
            'position' => 'Developer',
            'role' => Employee::ROLE_EMPLOYEE,
        ]);

        $firstTimeIn = Attendance::query()->create([
            'employee_id' => $employee->id,
            'rfid_uid' => $employee->rfid_uid,
            'attendance_type' => Type::TimeIn->value,
            'attendance_date' => '2026-06-17',
            'time_in' => '2026-06-17 18:37:00',
        ]);

        $attendance = app(AttendanceService::class)->recordAttendance(new Request([
            'rfid' => $employee->rfid_uid,
            'latitude' => 0,
            'longitude' => 0,
            'occurred_at' => '2026-06-17 18:40:00',
        ]));

        $firstTimeIn->refresh();

        $this->assertNotSame($firstTimeIn->id, $attendance->id);
        $this->assertSame(Type::TimeIn->value, $firstTimeIn->attendance_type->value);
        $this->assertNull($firstTimeIn->time_out);

        $this->assertSame(Type::TimeOut->value, $attendance->attendance_type->value);
        $this->assertSame('2026-06-17 18:40:00', Carbon::parse($attendance->getRawOriginal('time_in'))->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-17 18:40:00', Carbon::parse($attendance->getRawOriginal('time_out'))->format('Y-m-d H:i:s'));
        $this->assertEquals(0.05, (float) $attendance->total_hours);
        $this->assertEquals(0.05, (float) $firstTimeIn->total_hours);
        $this->assertSame(2, Attendance::query()->count());
    }

    public function test_newer_same_day_scan_becomes_the_time_out_marker(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'EMP-001',
            'rfid_uid' => 'RFID-001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'middle_name' => 'Byron',
            'date_of_birth' => '1990-01-01',
            'position' => 'Developer',
            'role' => Employee::ROLE_EMPLOYEE,
        ]);

        $previousLastScan = Attendance::query()->create([
            'employee_id' => $employee->id,
            'rfid_uid' => $employee->rfid_uid,
            'attendance_type' => Type::TimeOut->value,
            'attendance_date' => '2026-06-17',
            'time_in' => '2026-06-17 18:37:00',
            'time_out' => '2026-06-17 18:37:00',
            'total_hours' => 0.0,
        ]);

        $attendance = app(AttendanceService::class)->recordAttendance(new Request([
            'rfid' => $employee->rfid_uid,
            'latitude' => 0,
            'longitude' => 0,
            'occurred_at' => '2026-06-17 18:40:00',
        ]));

        $previousLastScan->refresh();

        $this->assertSame(Type::TimeIn->value, $previousLastScan->attendance_type->value);
        $this->assertNull($previousLastScan->time_out);

        $this->assertSame(Type::TimeOut->value, $attendance->attendance_type->value);
        $this->assertSame('2026-06-17 18:40:00', Carbon::parse($attendance->getRawOriginal('time_out'))->format('Y-m-d H:i:s'));
        $this->assertEquals(0.05, (float) $attendance->total_hours);
        $this->assertSame(2, Attendance::query()->count());
    }

    public function test_late_next_day_scan_closes_previous_time_in_at_midnight_and_records_new_time_in(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'EMP-001',
            'rfid_uid' => 'RFID-001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'middle_name' => 'Byron',
            'date_of_birth' => '1990-01-01',
            'position' => 'Developer',
            'role' => Employee::ROLE_EMPLOYEE,
        ]);

        $previousTimeIn = Attendance::query()->create([
            'employee_id' => $employee->id,
            'rfid_uid' => $employee->rfid_uid,
            'attendance_type' => Type::TimeIn->value,
            'attendance_date' => '2026-06-17',
            'time_in' => '2026-06-17 18:37:00',
        ]);

        $attendance = app(AttendanceService::class)->recordAttendance(new Request([
            'rfid' => $employee->rfid_uid,
            'latitude' => 0,
            'longitude' => 0,
            'occurred_at' => '2026-06-18 18:20:00',
        ]));

        $previousTimeIn->refresh();

        $this->assertSame(Type::TimeIn->value, $previousTimeIn->attendance_type->value);
        $this->assertNull($previousTimeIn->time_out);
        $this->assertNull($previousTimeIn->total_hours);

        $this->assertNotSame($previousTimeIn->id, $attendance->id);
        $this->assertSame(Type::TimeIn->value, $attendance->attendance_type->value);
        $this->assertSame('2026-06-18 18:20:00', Carbon::parse($attendance->getRawOriginal('time_in'))->format('Y-m-d H:i:s'));
        $this->assertNull($attendance->time_out);
    }
}
