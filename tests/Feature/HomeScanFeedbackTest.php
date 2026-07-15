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

    public function test_attendance_schedule_settings_default_and_clamp_face_capture_ratios(): void
    {
        $settings = app(AttendanceScheduleSettings::class);

        $this->assertSame(0.50, $settings->faceCaptureWidthRatio());
        $this->assertSame(0.68, $settings->faceCaptureHeightRatio());

        GeneralSetting::query()->create([
            'site_name' => 'TimeClock',
            'more_configs' => [
                'face_capture_width_ratio' => '1.25',
                'face_capture_height_ratio' => '0.10',
            ],
        ]);

        $cache = new \ReflectionProperty(AttendanceScheduleSettings::class, 'cachedSettings');
        $cache->setValue(null, null);

        $settings = app(AttendanceScheduleSettings::class);

        $this->assertSame(1.0, $settings->faceCaptureWidthRatio());
        $this->assertSame(0.25, $settings->faceCaptureHeightRatio());
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

        $this
            ->withSession(['timeclock_unlocked_by' => 'test-admin'])
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Home')
                ->where('attendanceSchedule.show_scan_status_messages', true)
                ->where('attendanceSchedule.face_capture_width_ratio', '0.5')
                ->where('attendanceSchedule.face_capture_height_ratio', '0.68')
                ->where('scanStatusMessages.idle', 'RFID and fingerprint scanners are listening.')
                ->where('scanStatusMessages.rfid_not_recognized', 'RFID card not recognized.')
                ->where('scanStatusMessages.fingerprint_waiting', 'Scan your registered finger on the scanner.')
                ->where('scanStatusMessages.fingerprint_not_found', 'Fingerprint not found.')
                ->where('scanStatusMessages.fingerprint_matched', 'Fingerprint matched. Starting facial verification...')
                ->where('scanStatusMessages.attendance_recorded', 'Attendance recorded successfully.')
                ->etc()
            );
    }

    public function test_home_payload_includes_configured_face_capture_ratios(): void
    {
        GeneralSetting::query()->create([
            'site_name' => 'TimeClock',
            'more_configs' => [
                'face_capture_width_ratio' => '0.44',
                'face_capture_height_ratio' => '0.62',
            ],
        ]);

        $this
            ->withSession(['timeclock_unlocked_by' => 'test-admin'])
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Home')
                ->where('attendanceSchedule.face_capture_width_ratio', '0.44')
                ->where('attendanceSchedule.face_capture_height_ratio', '0.62')
                ->etc()
            );
    }

    public function test_rfid_employee_verification_failure_returns_rfid_public_message(): void
    {
        $this
            ->withSession(['timeclock_unlocked_by' => 'test-admin'])
            ->postJson('/attendance/verify-employee', [
                'employee_id' => 'UNKNOWN-RFID',
                'attendance_method' => AttendanceMethod::RFID->value,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'RFID card not recognized.')
            ->assertJsonPath('errors.employee_id.0', 'RFID card not recognized.');
    }

    public function test_frontend_admin_unlock_uses_native_admin_login_handoff(): void
    {
        $unlockPage = file_get_contents(resource_path('js/Pages/TimeclockUnlock.vue'));

        $this->assertStringContainsString("submitAdminAction('dashboard')", $unlockPage);
        $this->assertStringContainsString('Open admin dashboard', $unlockPage);
        $this->assertStringContainsString("'dashboard'", $unlockPage);
        $this->assertStringContainsString('window.location.assign(redirect)', $unlockPage);
        $this->assertStringNotContainsString("form.action = '/admin/login'", $unlockPage);
        $this->assertStringNotContainsString('form.submit()', $unlockPage);
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

        $this->assertStringContainsString("if (!automatic) {\n            setTemporaryScannerError('fingerprint_not_found')", $cameraCard);
        $this->assertStringNotContainsString(
            "} catch (error) {\n        setTemporaryScannerError('fingerprint_not_found')",
            $cameraCard,
        );
    }

    public function test_fingerprint_waiting_event_with_not_recognized_message_shows_not_found_message(): void
    {
        $cameraCard = file_get_contents(resource_path('js/Components/Home/CameraCard.vue'));

        $this->assertStringContainsString(".includes('not recognized')", $cameraCard);
        $this->assertStringContainsString("setTemporaryScannerError('fingerprint_not_found')", $cameraCard);
    }

    public function test_scanner_status_feedback_and_face_auth_states_are_explicit(): void
    {
        $cameraCard = file_get_contents(resource_path('js/Components/Home/CameraCard.vue'));

        $this->assertStringContainsString('const showFaceCheckOnly = computed(() =>', $cameraCard);
        $this->assertStringContainsString("faceStatusText.value.startsWith('Checking face for ')", $cameraCard);
        $this->assertStringContainsString('v-if="showFaceCheckOnly"', $cameraCard);
        $this->assertStringContainsString('v-if="!showFaceCheckOnly"', $cameraCard);
        $this->assertStringContainsString('data-face-auth-status="checking-employee"', $cameraCard);
        $this->assertStringContainsString('<div v-else class="w-full">', $cameraCard);
        $this->assertStringContainsString('{{ scannerStatusText }}', $cameraCard);
        $this->assertStringContainsString('{{ processingLabel }}', $cameraCard);
        $this->assertStringContainsString(
            'data-scanner-status-version="20260715-always-on"',
            $cameraCard,
        );
        $this->assertStringNotContainsString('absolute inset-0 z-10', $cameraCard);
        $this->assertStringNotContainsString(
            'v-if="props.attendanceSchedule.show_scan_status_messages"',
            $cameraCard,
        );
    }
}
