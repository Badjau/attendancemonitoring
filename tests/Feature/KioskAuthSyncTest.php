<?php

namespace Tests\Feature;

use App\Enums\Attendance\AttendanceMethod;
use App\Models\Attendance;
use App\Models\Employee;
use App\Services\KioskAuthSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KioskAuthSyncTest extends TestCase
{
    use RefreshDatabase;

    private const KIOSK_TOKEN = 'test-kiosk-token';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.kiosk.token' => self::KIOSK_TOKEN]);
    }

    public function test_kiosk_auth_full_sync_returns_auth_safe_employee_records(): void
    {
        config(['services.kiosk_auth.hash_salt' => 'test-kiosk-salt']);

        $employee = Employee::query()->create([
            'employee_id' => 'EMP-100',
            'rfid_uid' => 'RFID-100',
            'password' => '1234',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'date_of_birth' => '1990-01-01',
            'position' => 'Developer',
            'role' => Employee::ROLE_EMPLOYEE,
        ]);

        $employee->faceEmbeddings()->create([
            'embedding' => [0.1, 0.2, 0.3],
            'image_hash' => hash('sha256', 'face-1'),
            'model_name' => 'SFace',
            'detector_backend' => 'yunet',
        ]);

        $rfidHash = app(KioskAuthSyncService::class)->rfidHash('RFID-100');
        $pinVerifier = app(KioskAuthSyncService::class)->pinVerifier('1234');

        $response = $this
            ->withHeader('X-Kiosk-Api-Token', self::KIOSK_TOKEN)
            ->getJson('/api/kiosk/auth/full')
            ->assertOk()
            ->assertJsonPath('manifest.employee_count', 1)
            ->assertJsonPath('records.0.employee_number', 'EMP-100')
            ->assertJsonPath('records.0.display_name', 'Ada Lovelace')
            ->assertJsonPath('records.0.rfid_hashes.0', $rfidHash)
            ->assertJsonPath('records.0.keypad_pin_hash', $pinVerifier)
            ->assertJsonPath('records.0.face_embeddings.0.vector.0', 0.1)
            ->assertJsonMissingPath('records.0.password')
            ->assertJsonMissingPath('records.0.rfid_uid');
    }

    public function test_kiosk_attendance_sync_records_cache_metadata(): void
    {
        $employee = Employee::query()->create([
            'employee_id' => 'EMP-200',
            'rfid_uid' => 'RFID-200',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'date_of_birth' => '1990-01-01',
            'position' => 'Developer',
            'role' => Employee::ROLE_EMPLOYEE,
        ]);

        $this
            ->withHeader('X-Kiosk-Api-Token', self::KIOSK_TOKEN)
            ->postJson('/api/kiosk/attendance/sync', [
                'records' => [[
                    'offline_uuid' => 'offline-kiosk-200',
                    'employee_id' => $employee->employee_id,
                    'auth_method' => AttendanceMethod::RFID->value,
                    'kiosk_id' => 'front-door',
                    'local_recorded_at' => '2026-07-17T08:00:00+08:00',
                    'auth_cache_revision' => 10,
                    'cache_state_at_record_time' => 'expired',
                    'matched_auth_revision' => 9,
                    'latitude' => 14.5995,
                    'longitude' => 120.9842,
                    'metadata' => [
                        'source' => 'indexeddb-auth-cache',
                    ],
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('results.0.offline_uuid', 'offline-kiosk-200')
            ->assertJsonPath('results.0.status', 'accepted_with_warning');

        $attendance = Attendance::query()->where('offline_id', 'offline-kiosk-200')->firstOrFail();

        $this->assertSame(10, $attendance->auth_cache_revision);
        $this->assertSame('expired', $attendance->cache_state_at_record_time);
        $this->assertSame(9, $attendance->matched_auth_revision);
        $this->assertSame('front-door', $attendance->auth_metadata['kiosk_id']);
        $this->assertSame('indexeddb-auth-cache', $attendance->auth_metadata['source']);
    }

    public function test_kiosk_attendance_sync_stores_offline_capture_image(): void
    {
        Storage::fake('public');

        $employee = Employee::query()->create([
            'employee_id' => 'EMP-IMG',
            'rfid_uid' => 'RFID-IMG',
            'first_name' => 'Image',
            'last_name' => 'Capture',
            'date_of_birth' => '1990-01-01',
            'position' => 'Developer',
            'role' => Employee::ROLE_EMPLOYEE,
        ]);

        $response = $this
            ->withHeader('X-Kiosk-Api-Token', self::KIOSK_TOKEN)
            ->postJson('/api/kiosk/attendance/sync', [
                'records' => [[
                    'offline_uuid' => 'offline-kiosk-image',
                    'employee_id' => $employee->employee_id,
                    'auth_method' => AttendanceMethod::FACE->value,
                    'local_recorded_at' => '2026-07-17T08:00:00+08:00',
                    'latitude' => 14.5995,
                    'longitude' => 120.9842,
                    'attendance_image' => $this->pngDataUrl(),
                ]],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('results.0.status', 'accepted');

        $attendance = Attendance::query()->where('offline_id', 'offline-kiosk-image')->firstOrFail();
        $media = $attendance->getFirstMedia('time-in-image');

        $this->assertNotNull($media);
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
    }

    public function test_kiosk_attendance_sync_returns_per_record_rejection_results(): void
    {
        $this
            ->withHeader('X-Kiosk-Api-Token', self::KIOSK_TOKEN)
            ->postJson('/api/kiosk/attendance/sync', [
                'records' => [[
                    'offline_uuid' => 'offline-kiosk-missing',
                    'employee_id' => 'MISSING-EMPLOYEE',
                    'auth_method' => AttendanceMethod::RFID->value,
                    'local_recorded_at' => '2026-07-17T08:00:00+08:00',
                    'cache_state_at_record_time' => 'fresh',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('results.0.offline_uuid', 'offline-kiosk-missing')
            ->assertJsonPath('results.0.status', 'needs_review')
            ->assertJsonPath('results.0.message', 'Employee is not existing.');
    }

    public function test_kiosk_api_rejects_missing_token(): void
    {
        $this
            ->getJson('/api/kiosk/auth/manifest')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthorized kiosk API request.');
    }

    public function test_kiosk_api_rejects_invalid_token(): void
    {
        $this
            ->withHeader('X-Kiosk-Api-Token', 'wrong-token')
            ->getJson('/api/kiosk/auth/manifest')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthorized kiosk API request.');
    }

    private function pngDataUrl(): string
    {
        return 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=';
    }
}
