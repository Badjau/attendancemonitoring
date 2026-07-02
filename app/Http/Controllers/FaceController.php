<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FaceController extends Controller
{
    public function employeeEmbeddings(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeFaceService($request);

        $embeddings = $employee->faceEmbeddings()
            ->oldest('id')
            ->get()
            ->map(fn ($embedding): array => [
                'id' => $embedding->id,
                'employee_id' => $employee->employee_id,
                'embedding' => $embedding->embedding,
                'image_hash' => $embedding->image_hash,
                'pose_label' => $embedding->pose_label,
                'model_name' => $embedding->model_name,
                'detector_backend' => $embedding->detector_backend,
                'quality' => $embedding->quality,
                'created_at' => $embedding->created_at?->toISOString(),
                'updated_at' => $embedding->updated_at?->toISOString(),
            ])
            ->values();

        return response()->json([
            'employee_id' => $employee->employee_id,
            'embeddings' => $embeddings,
            'enrollment_count' => $embeddings->count(),
            'last_enrolled_at' => $embeddings->max('updated_at'),
        ]);
    }

    public function storeEmployeeEmbedding(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeFaceService($request);

        $validated = $request->validate([
            'embedding' => ['required', 'array', 'min:1'],
            'embedding.*' => ['numeric'],
            'image_hash' => ['required', 'string', 'max:64'],
            'pose_label' => ['nullable', 'string', 'max:100'],
            'model_name' => ['required', 'string', 'max:100'],
            'detector_backend' => ['required', 'string', 'max:100'],
            'quality' => ['nullable', 'array'],
            'profile_image_base64' => ['nullable', 'string'],
            'reset_existing' => ['nullable', 'boolean'],
        ]);

        $profileImageBase64 = $validated['profile_image_base64'] ?? null;
        $resetExisting = (bool) ($validated['reset_existing'] ?? false)
            || $employee->faceEmbeddings()->count() >= 3;
        unset($validated['profile_image_base64']);
        unset($validated['reset_existing']);

        if ($resetExisting) {
            $employee->faceEmbeddings()->delete();
            $employee->clearMediaCollection('employee-profile');
        }

        $shouldSaveProfileImage = filled($profileImageBase64) && $employee->getFirstMedia('employee-profile') === null;
        $jpegBase64 = $shouldSaveProfileImage
            ? $this->compressedJpegBase64($profileImageBase64)
            : null;

        $embedding = $employee->faceEmbeddings()->create($validated);
        $profileImageSaved = false;

        if ($shouldSaveProfileImage && $jpegBase64 !== null) {
            $employee
                ->addMediaFromBase64($jpegBase64, 'image/jpeg', 'image/png')
                ->usingFileName($this->faceProfileFileName($employee, $validated['image_hash']))
                ->withCustomProperties([
                    'source' => 'face_enrollment',
                    'image_hash' => $validated['image_hash'],
                    'saved_from_embedding_id' => $embedding->id,
                ])
                ->toMediaCollection('employee-profile');

            $profileImageSaved = true;
        }

        return response()->json([
            'id' => $embedding->id,
            'employee_id' => $employee->employee_id,
            'created_at' => $embedding->created_at?->toISOString(),
            'updated_at' => $embedding->updated_at?->toISOString(),
            'profile_image_saved' => $profileImageSaved,
            'profile_image_url' => $employee->fresh()->employeeProfileUrl(),
        ], 201);
    }

    public function destroyEmployeeEmbeddings(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeFaceService($request);

        $deletedEmbeddings = $employee->faceEmbeddings()->delete();
        $deletedProfileImages = $employee->getMedia('employee-profile')->count();
        $employee->clearMediaCollection('employee-profile');

        return response()->json([
            'employee_id' => $employee->employee_id,
            'deleted_embeddings' => $deletedEmbeddings,
            'deleted_profile_images' => $deletedProfileImages,
        ]);
    }

    private function compressedJpegBase64(string $base64Image): string
    {
        $base64Image = preg_replace('/^data:image\/(?:jpeg|jpg|png);base64,/', '', trim($base64Image)) ?? '';
        $imageBytes = base64_decode($base64Image, true);

        if ($imageBytes === false) {
            throw ValidationException::withMessages([
                'profile_image_base64' => 'The profile image must be valid base64.',
            ]);
        }

        $imageInfo = @getimagesizefromstring($imageBytes);
        $mimeType = $imageInfo['mime'] ?? null;

        if (! in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages([
                'profile_image_base64' => 'The profile image must be a JPEG or PNG image.',
            ]);
        }

        $source = @imagecreatefromstring($imageBytes);

        if ($source === false) {
            throw ValidationException::withMessages([
                'profile_image_base64' => 'The profile image could not be processed.',
            ]);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            imagedestroy($source);

            throw ValidationException::withMessages([
                'profile_image_base64' => 'The profile image could not be processed.',
            ]);
        }

        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

        ob_start();
        imagejpeg($canvas, null, 82);
        $jpegBytes = ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        if ($jpegBytes === false || $jpegBytes === '') {
            throw ValidationException::withMessages([
                'profile_image_base64' => 'The profile image could not be compressed.',
            ]);
        }

        return base64_encode($jpegBytes);
    }

    private function faceProfileFileName(Employee $employee, string $imageHash): string
    {
        return sprintf(
            'face-%s-%s.jpg',
            Str::slug($employee->employee_id),
            Str::limit($imageHash, 64, '')
        );
    }

    private function authorizeFaceService(Request $request): void
    {
        $token = (string) config('services.face_embeddings.token', '');

        if ($token === '') {
            return;
        }

        $provided = (string) $request->bearerToken();

        if (! hash_equals($token, $provided)) {
            throw ValidationException::withMessages([
                'token' => 'Face embedding API token is invalid.',
            ]);
        }
    }
}
