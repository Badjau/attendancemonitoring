<?php

namespace App\Services;

use App\Enums\Attendance\Type;
use Carbon\Carbon;
use Joaopaulolndev\FilamentGeneralSettings\Models\GeneralSetting;

class AttendanceScheduleSettings
{
    private const DEFAULTS = [
        'time_in_start' => '08:00',
        'time_out_start' => '18:00',
        'duplicate_scan_window_seconds' => '60',
        'same_employee_auth_cooldown_minutes' => '60',
        'face_capture_width_ratio' => '0.50',
        'face_capture_height_ratio' => '0.68',
        'face_verification_window_ms' => '6000',
        'face_usable_frame_target' => '3',
        'face_required_match_count' => '2',
        'face_only_usable_frame_target' => '5',
        'face_only_required_match_count' => '3',
        'show_face_attendance_button' => false,
        'show_scan_status_messages' => true,
        'scan_status_idle' => 'RFID and fingerprint scanners are listening.',
        'scan_status_rfid_not_recognized' => 'RFID card not recognized.',
        'scan_status_fingerprint_waiting' => 'Scan your registered finger on the scanner.',
        'scan_status_fingerprint_not_found' => 'Fingerprint not found.',
        'scan_status_fingerprint_matched' => 'Fingerprint matched. Starting facial verification...',
        'scan_status_attendance_recorded' => 'Attendance recorded successfully.',
    ];

    private static ?array $cachedSettings = null;

    public function inferAttendanceType(Carbon $now): string
    {
        return (($now->hour * 60) + $now->minute) < $this->timeOutStartMinutes()
            ? Type::TimeIn->value
            : Type::TimeOut->value;
    }

    /**
     * @return array<string, bool|string>
     */
    public function toArray(): array
    {
        return [
            'time_in_start' => $this->setting('time_in_start'),
            'time_out_start' => $this->setting('time_out_start'),
            'duplicate_scan_window_seconds' => (string) $this->duplicateScanWindowSeconds(),
            'same_employee_auth_cooldown_minutes' => (string) $this->sameEmployeeAuthCooldownMinutes(),
            'face_capture_width_ratio' => (string) $this->faceCaptureWidthRatio(),
            'face_capture_height_ratio' => (string) $this->faceCaptureHeightRatio(),
            'face_verification_window_ms' => (string) $this->faceVerificationWindowMs(),
            'face_usable_frame_target' => (string) $this->faceUsableFrameTarget(),
            'face_required_match_count' => (string) $this->faceRequiredMatchCount(),
            'face_only_usable_frame_target' => (string) $this->faceOnlyUsableFrameTarget(),
            'face_only_required_match_count' => (string) $this->faceOnlyRequiredMatchCount(),
            'show_face_attendance_button' => $this->showFaceAttendanceButton(),
            'show_scan_status_messages' => $this->showScanStatusMessages(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function scanStatusMessages(): array
    {
        return [
            'idle' => $this->textSetting('scan_status_idle'),
            'rfid_not_recognized' => $this->textSetting('scan_status_rfid_not_recognized'),
            'fingerprint_waiting' => $this->textSetting('scan_status_fingerprint_waiting'),
            'fingerprint_not_found' => $this->textSetting('scan_status_fingerprint_not_found'),
            'fingerprint_matched' => $this->textSetting('scan_status_fingerprint_matched'),
            'attendance_recorded' => $this->textSetting('scan_status_attendance_recorded'),
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

    public function sameEmployeeAuthCooldownMinutes(): int
    {
        return $this->integerSetting('same_employee_auth_cooldown_minutes', 0, 1440);
    }

    public function faceCaptureWidthRatio(): float
    {
        return $this->ratioSetting('face_capture_width_ratio');
    }

    public function faceCaptureHeightRatio(): float
    {
        return $this->ratioSetting('face_capture_height_ratio');
    }

    public function faceVerificationWindowMs(): int
    {
        return $this->integerSetting('face_verification_window_ms', 4000, 15000);
    }

    public function faceUsableFrameTarget(): int
    {
        return $this->integerSetting('face_usable_frame_target', 3, 10);
    }

    public function faceRequiredMatchCount(): int
    {
        return min($this->faceUsableFrameTarget(), $this->integerSetting('face_required_match_count', 2, 10));
    }

    public function faceOnlyUsableFrameTarget(): int
    {
        return $this->integerSetting('face_only_usable_frame_target', 3, 10);
    }

    public function faceOnlyRequiredMatchCount(): int
    {
        return min($this->faceOnlyUsableFrameTarget(), $this->integerSetting('face_only_required_match_count', 2, 10));
    }

    public function showFaceAttendanceButton(): bool
    {
        $value = $this->getAllSettings()['show_face_attendance_button'] ?? self::DEFAULTS['show_face_attendance_button'];

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function showScanStatusMessages(): bool
    {
        $value = $this->getAllSettings()['show_scan_status_messages'] ?? self::DEFAULTS['show_scan_status_messages'];

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

    private function textSetting(string $key): string
    {
        $value = $this->getAllSettings()[$key] ?? null;

        if (is_string($value) && filled(trim($value))) {
            return trim($value);
        }

        return self::DEFAULTS[$key];
    }

    private function ratioSetting(string $key): float
    {
        $value = $this->getAllSettings()[$key] ?? self::DEFAULTS[$key];
        $ratio = is_numeric($value) ? (float) $value : (float) self::DEFAULTS[$key];

        return round(max(0.25, min(1.0, $ratio)), 2);
    }

    private function integerSetting(string $key, int $min, int $max): int
    {
        $value = $this->getAllSettings()[$key] ?? self::DEFAULTS[$key];
        $integer = is_numeric($value) ? (int) $value : (int) self::DEFAULTS[$key];

        return max($min, min($max, $integer));
    }

    private function getAllSettings(): array
    {
        // Cache at request level to avoid multiple database queries
        if (self::$cachedSettings === null) {
            $settings = GeneralSetting::query()->first();
            $configs = $settings?->more_configs ?? [];

            self::$cachedSettings = is_array($configs) ? $configs : [];
        }

        return self::$cachedSettings;
    }

    private function timeToMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return ($hours * 60) + $minutes;
    }
}
