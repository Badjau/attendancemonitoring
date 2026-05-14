<?php

namespace App\Services;

use App\Enums\Attendance\Type;
use Carbon\Carbon;
use Joaopaulolndev\FilamentGeneralSettings\Models\GeneralSetting;

class AttendanceScheduleSettings
{
    private const DEFAULTS = [
        'time_in_start' => '00:00',
        'time_in_end' => '16:00',
        'time_out_start' => '16:01',
        'time_out_end' => '23:59',
    ];

    public function inferAttendanceType(Carbon $now): string
    {
        $minute = ($now->hour * 60) + $now->minute;

        if ($this->isMinuteWithinRange($minute, $this->timeInStartMinutes(), $this->timeInEndMinutes())) {
            return Type::TimeIn->value;
        }

        if ($this->isMinuteWithinRange($minute, $this->timeOutStartMinutes(), $this->timeOutEndMinutes())) {
            return Type::TimeOut->value;
        }

        return $minute <= $this->timeInEndMinutes()
            ? Type::TimeIn->value
            : Type::TimeOut->value;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'time_in_start' => $this->setting('time_in_start'),
            'time_in_end' => $this->setting('time_in_end'),
            'time_out_start' => $this->setting('time_out_start'),
            'time_out_end' => $this->setting('time_out_end'),
        ];
    }

    private function timeInStartMinutes(): int
    {
        return $this->timeToMinutes($this->setting('time_in_start'));
    }

    private function timeInEndMinutes(): int
    {
        return $this->timeToMinutes($this->setting('time_in_end'));
    }

    private function timeOutStartMinutes(): int
    {
        return $this->timeToMinutes($this->setting('time_out_start'));
    }

    private function timeOutEndMinutes(): int
    {
        return $this->timeToMinutes($this->setting('time_out_end'));
    }

    private function setting(string $key): string
    {
        $value = GeneralSetting::query()->value("more_configs->{$key}");

        if (is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        if (is_string($value) && filled($value)) {
            return Carbon::parse($value)->format('H:i');
        }

        return self::DEFAULTS[$key];
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }

    private function isMinuteWithinRange(int $minute, int $start, int $end): bool
    {
        if ($start <= $end) {
            return $minute >= $start && $minute <= $end;
        }

        return $minute >= $start || $minute <= $end;
    }
}
