<?php

namespace App\Services;

use App\Enums\Attendance\Type;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Joaopaulolndev\FilamentGeneralSettings\Models\GeneralSetting;

class AttendanceScheduleSettings
{
    private const DEFAULTS = [
        'time_in_start' => '00:01', // 12:01 AM
        'time_in_end' => '23:59', // 11:59 PM
        'time_out_start' => '00:00', // 12:00 AM
        'time_out_end' => '00:00', // 12:00 AM
    ];

    private static ?array $cachedSettings = null;

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
        // Load all settings once per request to avoid multiple queries
        $allSettings = $this->getAllSettings();
        $value = $allSettings[$key] ?? null;

        if (is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value;
        }

        if (is_string($value) && filled($value)) {
            return Carbon::parse($value)->format('H:i');
        }

        return self::DEFAULTS[$key];
    }

    private function getAllSettings(): array
    {
        // Cache at request level to avoid multiple database queries
        if (self::$cachedSettings === null) {
            self::$cachedSettings = Cache::remember('attendance_schedule_settings', 3600, function () {
                $settings = GeneralSetting::query()->first();
                $configs = $settings?->more_configs ?? [];

                return is_array($configs) ? $configs : [];
            });
        }

        return self::$cachedSettings;
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
