<?php

namespace App\Services;

use App\Enums\Attendance\Type;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Joaopaulolndev\FilamentGeneralSettings\Models\GeneralSetting;

class AttendanceScheduleSettings
{
    private const DEFAULTS = [
        'time_in_start' => '08:00',
        'time_out_start' => '18:00',
        'duplicate_scan_window_seconds' => '60',
        'show_face_attendance_button' => false,
    ];

    private static ?array $cachedSettings = null;

    public function inferAttendanceType(Carbon $now): string
    {
        return (($now->hour * 60) + $now->minute) < $this->timeOutStartMinutes()
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
            'time_out_start' => $this->setting('time_out_start'),
            'duplicate_scan_window_seconds' => (string) $this->duplicateScanWindowSeconds(),
            'show_face_attendance_button' => $this->showFaceAttendanceButton(),
        ];
    }

    public function shiftStart(Carbon $date): Carbon
    {
        return $date->copy()->setTimeFromTimeString($this->setting('time_in_start').':00');
    }

    public function shiftEnd(Carbon $date): Carbon
    {
        return $date->copy()->setTimeFromTimeString($this->setting('time_out_start').':00');
    }

    public function duplicateScanWindowSeconds(): int
    {
        $value = $this->getAllSettings()['duplicate_scan_window_seconds'] ?? self::DEFAULTS['duplicate_scan_window_seconds'];

        return max(0, min(3600, (int) $value));
    }

    public function showFaceAttendanceButton(): bool
    {
        $value = $this->getAllSettings()['show_face_attendance_button'] ?? self::DEFAULTS['show_face_attendance_button'];

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function timeOutStartMinutes(): int
    {
        return $this->timeToMinutes($this->setting('time_out_start'));
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
}
