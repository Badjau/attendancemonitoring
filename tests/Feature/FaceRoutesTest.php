<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\FaceAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class FaceRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_face_register_post_route_is_removed(): void
    {
        $postFaceRegisterRoutes = collect(Route::getRoutes())
            ->filter(fn ($route): bool => in_array('POST', $route->methods(), true))
            ->filter(fn ($route): bool => $route->uri() === 'face/register');

        $this->assertCount(0, $postFaceRegisterRoutes);
    }

    public function test_visible_face_attendance_button_is_removed_but_diagnostics_route_loads(): void
    {
        $cameraCard = file_get_contents(resource_path('js/Components/Home/CameraCard.vue'));

        $this->assertStringNotContainsString('<span class="text-sm">Face</span>', $cameraCard);
        $this->get('/face/diagnostics')->assertOk();
    }

    public function test_face_embedding_api_stores_and_serves_employee_vectors(): void
    {
        config(['services.face_embeddings.token' => 'test-token']);
        Storage::fake('public');

        $employee = $this->createEmployee();
        $firstImageHash = hash('sha256', 'face');

        $firstResponse = $this->withToken('test-token')
            ->postJson("/api/face/employees/{$employee->employee_id}/embeddings", [
                'embedding' => [0.1, 0.2, 0.3],
                'image_hash' => $firstImageHash,
                'pose_label' => 'front',
                'model_name' => 'SFace',
                'detector_backend' => 'yunet',
                'quality' => ['brightness' => 100],
                'profile_image_base64' => $this->pngBase64('first'),
            ])
            ->assertCreated()
            ->assertJsonPath('employee_id', 'EMP-001')
            ->assertJsonPath('profile_image_saved', true)
            ->assertJsonPath('profile_image_url', fn (string $url): bool => str_contains($url, '/storage/'));

        $this->assertCount(1, $employee->fresh()->getMedia('employee-profile'));
        $media = Media::query()->where('collection_name', 'employee-profile')->sole();
        $this->assertSame('face-emp-001-'.$firstImageHash.'.jpg', $media->file_name);
        $this->assertSame('public', $media->disk);
        $this->assertSame('image/jpeg', $media->mime_type);
        $this->assertSame('face_enrollment', $media->getCustomProperty('source'));
        $this->assertSame($firstImageHash, $media->getCustomProperty('image_hash'));
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
        $this->assertStringStartsWith('/storage/', parse_url($firstResponse->json('profile_image_url'), PHP_URL_PATH));

        $this->withToken('test-token')
            ->postJson("/api/face/employees/{$employee->employee_id}/embeddings", [
                'embedding' => [0.4, 0.5, 0.6],
                'image_hash' => hash('sha256', 'second-face'),
                'pose_label' => 'left',
                'model_name' => 'SFace',
                'detector_backend' => 'yunet',
                'quality' => ['brightness' => 110],
                'profile_image_base64' => $this->pngBase64('second'),
            ])
            ->assertCreated()
            ->assertJsonPath('profile_image_saved', false);

        $this->assertCount(1, $employee->fresh()->getMedia('employee-profile'));
        $this->assertSame($media->id, $employee->fresh()->getFirstMedia('employee-profile')->id);
        $this->assertSame($firstResponse->json('profile_image_url'), $employee->fresh()->employeeProfileUrl());

        $this->withToken('test-token')
            ->getJson("/api/face/employees/{$employee->employee_id}/embeddings")
            ->assertOk()
            ->assertJsonMissingPath('embeddings.0.profile_image_base64')
            ->assertJsonPath('enrollment_count', 2)
            ->assertJsonPath('embeddings.0.embedding', [0.1, 0.2, 0.3])
            ->assertJsonPath('embeddings.0.image_hash', $firstImageHash);
    }

    public function test_face_embedding_manifest_serves_current_server_vectors(): void
    {
        config(['services.face_embeddings.token' => 'test-token']);

        $firstEmployee = $this->createEmployee();
        $secondEmployee = $this->createEmployee('EMP-002', 'RFID-002');

        $firstEmployee->faceEmbeddings()->create([
            'embedding' => [0.1, 0.2, 0.3],
            'image_hash' => hash('sha256', 'first-face'),
            'model_name' => 'SFace',
            'detector_backend' => 'yunet',
        ]);
        $secondEmployee->faceEmbeddings()->create([
            'embedding' => [0.4, 0.5, 0.6],
            'image_hash' => hash('sha256', 'second-face'),
            'model_name' => 'SFace',
            'detector_backend' => 'yunet',
        ]);

        $this->withToken('test-token')
            ->getJson('/api/face/embeddings')
            ->assertOk()
            ->assertJsonPath('embedding_count', 2)
            ->assertJsonPath('employee_count', 2)
            ->assertJsonPath('embeddings.0.employee_id', 'EMP-001')
            ->assertJsonPath('embeddings.0.embedding', [0.1, 0.2, 0.3])
            ->assertJsonPath('embeddings.1.employee_id', 'EMP-002')
            ->assertJsonPath('embeddings.1.embedding', [0.4, 0.5, 0.6]);
    }

    public function test_employee_delete_notifies_face_service_cache(): void
    {
        config(['services.face.url' => 'https://127.0.0.1:8001']);
        Http::fake([
            'https://127.0.0.1:8001/api/employees/EMP-001' => Http::response([
                'employee_id' => 'EMP-001',
                'deleted' => 3,
            ]),
        ]);

        $employee = $this->createEmployee();

        $employee->delete();

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://127.0.0.1:8001/api/employees/EMP-001');
    }

    public function test_face_embedding_api_can_reset_employee_vectors_and_profile_image_for_updates(): void
    {
        config(['services.face_embeddings.token' => 'test-token']);
        Storage::fake('public');

        $employee = $this->createEmployee();

        $this->withToken('test-token')
            ->postJson("/api/face/employees/{$employee->employee_id}/embeddings", [
                'embedding' => [0.1, 0.2, 0.3],
                'image_hash' => hash('sha256', 'old-face'),
                'model_name' => 'SFace',
                'detector_backend' => 'yunet',
                'profile_image_base64' => $this->pngBase64('old'),
            ])
            ->assertCreated()
            ->assertJsonPath('profile_image_saved', true);

        $this->assertCount(1, $employee->fresh()->faceEmbeddings);
        $this->assertCount(1, $employee->fresh()->getMedia('employee-profile'));

        $this->withToken('test-token')
            ->deleteJson("/api/face/employees/{$employee->employee_id}/embeddings")
            ->assertOk()
            ->assertJsonPath('deleted_embeddings', 1)
            ->assertJsonPath('deleted_profile_images', 1);

        $this->assertCount(0, $employee->fresh()->faceEmbeddings);
        $this->assertCount(0, $employee->fresh()->getMedia('employee-profile'));

        $this->withToken('test-token')
            ->postJson("/api/face/employees/{$employee->employee_id}/embeddings", [
                'embedding' => [0.4, 0.5, 0.6],
                'image_hash' => hash('sha256', 'new-face'),
                'model_name' => 'SFace',
                'detector_backend' => 'yunet',
                'profile_image_base64' => $this->pngBase64('new'),
                'reset_existing' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('profile_image_saved', true);

        $this->assertCount(1, $employee->fresh()->faceEmbeddings);
        $this->assertCount(1, $employee->fresh()->getMedia('employee-profile'));
    }

    public function test_employee_profile_url_is_empty_without_media_and_public_storage_url_with_media(): void
    {
        Storage::fake('public');

        $employee = $this->createEmployee();

        $this->assertSame('', $employee->employeeProfileUrl());

        $employee
            ->addMediaFromString(base64_decode($this->pngBase64('profile'), true))
            ->usingFileName('profile.png')
            ->toMediaCollection('employee-profile', 'public');

        $url = $employee->fresh()->employeeProfileUrl();
        $media = $employee->fresh()->getFirstMedia('employee-profile');

        $this->assertNotSame('', $url);
        $this->assertStringStartsWith('/storage/', parse_url($url, PHP_URL_PATH));
        $this->assertSame('public', $media->disk);
        Storage::disk('public')->assertExists($media->getPathRelativeToRoot());
    }

    public function test_admin_face_views_render_without_media_and_use_public_profile_url_with_media(): void
    {
        Storage::fake('public');

        $employee = $this->createEmployee();

        $this->blade('@include("filament.admin.employees.face-summary", ["employee" => $employee])', [
            'employee' => $employee,
        ])
            ->assertSee('No face registered yet')
            ->assertDontSee('<img', false);

        $employee
            ->addMediaFromString(base64_decode($this->pngBase64('view-profile'), true))
            ->usingFileName('view-profile.png')
            ->toMediaCollection('employee-profile', 'public');

        $profileUrl = $employee->fresh()->employeeProfileUrl();

        $this->blade('@include("filament.admin.employees.face-summary", ["employee" => $employee])', [
            'employee' => $employee->fresh(),
        ])
            ->assertSee('Registered face exists')
            ->assertSee($profileUrl, false);

        $this->blade('@include("filament.admin.employees.face-registration", ["employee" => $employee])', [
            'employee' => $employee->fresh(),
        ])
            ->assertSee('hasRegisteredFace: true', false);
    }

    public function test_face_embedding_api_replaces_complete_enrollment_even_without_reset_flag(): void
    {
        config(['services.face_embeddings.token' => 'test-token']);
        Storage::fake('public');

        $employee = $this->createEmployee();

        foreach ([1, 2, 3] as $capture) {
            $this->withToken('test-token')
                ->postJson("/api/face/employees/{$employee->employee_id}/embeddings", [
                    'embedding' => [$capture, 0.2, 0.3],
                    'image_hash' => hash('sha256', 'old-face-'.$capture),
                    'model_name' => 'SFace',
                    'detector_backend' => 'yunet',
                    'profile_image_base64' => $this->pngBase64('old-'.$capture),
                ])
                ->assertCreated();
        }

        $this->assertCount(3, $employee->fresh()->faceEmbeddings);

        $newImageHash = hash('sha256', 'replacement-face');

        $this->withToken('test-token')
            ->postJson("/api/face/employees/{$employee->employee_id}/embeddings", [
                'embedding' => [9.1, 9.2, 9.3],
                'image_hash' => $newImageHash,
                'model_name' => 'SFace',
                'detector_backend' => 'yunet',
                'profile_image_base64' => $this->pngBase64('replacement'),
            ])
            ->assertCreated()
            ->assertJsonPath('profile_image_saved', true);

        $employee->refresh();

        $this->assertCount(1, $employee->faceEmbeddings);
        $this->assertSame($newImageHash, $employee->faceEmbeddings()->sole()->image_hash);
        $this->assertCount(1, $employee->getMedia('employee-profile'));
    }

    public function test_face_attempt_api_stores_suspicious_audit_record_with_evidence(): void
    {
        Storage::fake('public');

        $employee = $this->createEmployee();

        $this->postJson('/api/face/attempts', [
            'candidate_employee_id' => $employee->employee_id,
            'decision' => 'fallback',
            'reason_code' => 'session_high_risk',
            'match_score' => 0.3,
            'liveness_score' => 0.2,
            'quality_score' => 0.7,
            'risk_score' => 0.8,
            'frame_count' => 4,
            'usable_frame_count' => 3,
            'matched_frame_count' => 1,
            'fallback_used' => true,
            'device_id' => 'kiosk-1',
            'session_id' => 'session-1',
            'metadata' => ['confidence' => 0.4],
            'evidence_image_base64' => $this->pngBase64('attempt'),
        ])
            ->assertCreated()
            ->assertJsonPath('suspicious', true);

        $attempt = FaceAttempt::query()->sole();

        $this->assertTrue($attempt->suspicious);
        $this->assertTrue($attempt->fallback_used);
        $this->assertSame($employee->id, $attempt->employee_id);
        $this->assertSame('session_high_risk', $attempt->reason_code);
        $this->assertCount(1, $attempt->getMedia('face-attempt-evidence'));
    }

    private function createEmployee(string $employeeId = 'EMP-001', string $rfidUid = 'RFID-001'): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'rfid_uid' => $rfidUid,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'middle_name' => 'Byron',
            'date_of_birth' => '1990-01-01',
            'position' => 'Developer',
            'role' => Employee::ROLE_EMPLOYEE,
        ]);
    }

    private function pngBase64(string $seed): string
    {
        $red = (hexdec(substr(hash('sha256', $seed), 0, 2)) % 200) + 30;
        $image = imagecreatetruecolor(2, 2);

        imagefill($image, 0, 0, imagecolorallocate($image, $red, 120, 160));

        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return base64_encode($bytes);
    }
}
