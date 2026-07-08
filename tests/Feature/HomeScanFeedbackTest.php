<?php

namespace Tests\Feature;

use App\Enums\Attendance\AttendanceMethod;
use App\Services\AttendanceScheduleSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Joaopaulolndev\FilamentGeneralSettings\Models\GeneralSetting;
use Tests\TestCase;

class HomeScanFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $cache = new \ReflectionProperty(AttendanceScheduleSettings::class, 'cachedSettings');
        $cache->setValue(null, null);
    }

    public function test_attendance_schedule_settings_include_scan_status_visibility(): void
    {
        GeneralSetting::query()->create([
            'site_name' => 'TimeClock',
            'more_configs' => [
                'show_scan_status_messages' => false,
            ],
        ]);

        $settings = app(AttendanceScheduleSettings::class)->toArray();

        $this->assertArrayHasKey('show_scan_status_messages', $settings);
        $this->assertFalse($settings['show_scan_status_messages']);
    }

    public function test_home_payload_includes_default_scan_status_messages(): void
    {
        GeneralSetting::query()->create([
            'site_name' => 'TimeClock',
            'more_configs' => [
                'scan_status_idle' => '',
                'scan_status_rfid_not_recognized' => '  ',
            ],
        ]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Home')
                ->where('attendanceSchedule.show_scan_status_messages', true)
                ->where('scanStatusMessages.idle', 'RFID and fingerprint scanners are listening.')
                ->where('scanStatusMessages.rfid_not_recognized', 'RFID card not recognized.')
                ->where('scanStatusMessages.fingerprint_waiting', 'Scan your registered finger on the scanner.')
                ->where('scanStatusMessages.fingerprint_not_found', 'Fingerprint not found.')
                ->where('scanStatusMessages.fingerprint_matched', 'Fingerprint matched. Starting facial verification...')
                ->where('scanStatusMessages.attendance_recorded', 'Attendance recorded successfully.')
                ->etc()
            );
    }

    public function test_rfid_employee_verification_failure_returns_rfid_public_message(): void
    {
        $this->postJson('/attendance/verify-employee', [
            'employee_id' => 'UNKNOWN-RFID',
            'attendance_method' => AttendanceMethod::RFID->value,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'RFID card not recognized.')
            ->assertJsonPath('errors.employee_id.0', 'RFID card not recognized.');
    }

    public function test_frontend_admin_unlock_uses_full_page_navigation_for_admin_redirect(): void
    {
        $unlockPage = file_get_contents(resource_path('js/Pages/TimeclockUnlock.vue'));

        $this->assertStringContainsString("redirect.startsWith('/admin')", $unlockPage);
        $this->assertStringContainsString('window.location.assign(redirect)', $unlockPage);
        $this->assertStringNotContainsString('router.visit(response.data.redirect ??', $unlockPage);
    }

    public function test_face_registration_finish_does_not_reload_or_replace_the_page(): void
    {
        $faceRegistration = file_get_contents(resource_path('js/filament-face-registration.js'));

        $this->assertStringNotContainsString('window.location.reload()', $faceRegistration);
        $this->assertStringNotContainsString('window.location.replace(', $faceRegistration);
        $this->assertStringNotContainsString("searchParams.has('redirect')", $faceRegistration);
    }

    public function test_automatic_fingerprint_timeout_does_not_show_not_found_message(): void
    {
        $cameraCard = file_get_contents(resource_path('js/Components/Home/CameraCard.vue'));

        $this->assertStringContainsString(
            "if (!automatic) {\n            setScannerStatus('fingerprint_not_found')",
            $cameraCard,
        );
        $this->assertStringNotContainsString(
            "} catch (error) {\n        setScannerStatus('fingerprint_not_found')",
            $cameraCard,
        );
    }

    public function test_fingerprint_waiting_event_with_not_recognized_message_shows_not_found_message(): void
    {
        $cameraCard = file_get_contents(resource_path('js/Components/Home/CameraCard.vue'));

        $this->assertStringContainsString(".includes('not recognized')", $cameraCard);
        $this->assertStringContainsString("setScannerStatus('fingerprint_not_found')", $cameraCard);
    }
}
