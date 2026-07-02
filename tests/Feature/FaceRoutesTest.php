<?php

namespace Tests\Feature;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->withToken('test-token')
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
            ->assertJsonPath('profile_image_saved', true);

        $this->assertCount(1, $employee->fresh()->getMedia('employee-profile'));
        $media = Media::query()->where('collection_name', 'employee-profile')->sole();
        $this->assertSame('face-emp-001-'.$firstImageHash.'.jpg', $media->file_name);
        $this->assertSame('face_enrollment', $media->getCustomProperty('source'));
        $this->assertSame($firstImageHash, $media->getCustomProperty('image_hash'));

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

        $this->withToken('test-token')
            ->getJson("/api/face/employees/{$employee->employee_id}/embeddings")
            ->assertOk()
            ->assertJsonMissingPath('embeddings.0.profile_image_base64')
            ->assertJsonPath('enrollment_count', 2)
            ->assertJsonPath('embeddings.0.embedding', [0.1, 0.2, 0.3])
            ->assertJsonPath('embeddings.0.image_hash', $firstImageHash);
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

    private function createEmployee(): Employee
    {
        return Employee::query()->create([
            'employee_id' => 'EMP-001',
            'rfid_uid' => 'RFID-001',
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
