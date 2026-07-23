<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\FaceAttempt;
use App\Models\FaceEmbedding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FaceController extends Controller
{
    public function embeddingsManifest(Request $request): JsonResponse
    {
        $this->authorizeFaceService($request);

        $embeddings = FaceEmbedding::query()
            ->with('employee:id,employee_id')
            ->oldest('id')
            ->get()
            ->filter(fn (FaceEmbedding $embedding): bool => $embedding->employee !== null)
            ->map(fn (FaceEmbedding $embedding): array => [
                'id' => $embedding->id,
                'employee_id' => $embedding->employee->employee_id,
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
            'embeddings' => $embeddings,
            'embedding_count' => $embeddings->count(),
            'employee_count' => $embeddings->pluck('employee_id')->unique()->count(),
            'generated_at' => now()->toISOString(),
        ]);
    }

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
        $jpegBytes = $shouldSaveProfileImage
            ? $this->compressedJpegBytes($profileImageBase64)
            : null;

        $embedding = $employee->faceEmbeddings()->create($validated);
        $profileImageSaved = false;

        if ($shouldSaveProfileImage && $jpegBytes !== null) {
            $employee
                ->addMediaFromString($jpegBytes)
                ->usingFileName($this->faceProfileFileName($employee, $validated['image_hash']))
                ->withCustomProperties([
                    'source' => 'face_enrollment',
                    'image_hash' => $validated['image_hash'],
                    'saved_from_embedding_id' => $embedding->id,
                ])
                ->toMediaCollection('employee-profile', 'public');

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

    public function storeAttempt(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'candidate_employee_id' => ['nullable', 'string', 'max:100'],
            'decision' => ['required', 'string', 'in:accept,retry,fallback'],
            'reason_code' => ['nullable', 'string', 'max:100'],
            'match_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'liveness_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'quality_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'risk_score' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'frame_count' => ['nullable', 'integer', 'min:0'],
            'usable_frame_count' => ['nullable', 'integer', 'min:0'],
            'matched_frame_count' => ['nullable', 'integer', 'min:0'],
            'fallback_used' => ['nullable', 'boolean'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'session_id' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'evidence_image_base64' => ['nullable', 'string'],
        ]);

        $employee = null;
        if (filled($validated['candidate_employee_id'] ?? null)) {
            $employee = Employee::query()
                ->where('employee_id', $validated['candidate_employee_id'])
                ->first();
        }

        $riskScore = (float) ($validated['risk_score'] ?? 1.0);
        $suspicious = $validated['decision'] !== 'accept'
            || $riskScore >= 0.45
            || in_array($validated['reason_code'] ?? null, ['multiple_faces', 'session_high_risk'], true);

        $attempt = FaceAttempt::query()->create([
            'employee_id' => $employee?->id,
            'candidate_employee_identifier' => $validated['candidate_employee_id'] ?? null,
            'decision' => $validated['decision'],
            'reason_code' => $validated['reason_code'] ?? null,
            'match_score' => $validated['match_score'] ?? null,
            'liveness_score' => $validated['liveness_score'] ?? null,
            'quality_score' => $validated['quality_score'] ?? null,
            'risk_score' => $validated['risk_score'] ?? null,
            'frame_count' => $validated['frame_count'] ?? 0,
            'usable_frame_count' => $validated['usable_frame_count'] ?? 0,
            'matched_frame_count' => $validated['matched_frame_count'] ?? 0,
            'fallback_used' => (bool) ($validated['fallback_used'] ?? $validated['decision'] === 'fallback'),
            'suspicious' => $suspicious,
            'device_id' => $validated['device_id'] ?? null,
            'session_id' => $validated['session_id'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
            'metadata' => $validated['metadata'] ?? null,
            'attempted_at' => now(),
        ]);

        if (filled($validated['evidence_image_base64'] ?? null)) {
            $attempt
                ->addMediaFromBase64($validated['evidence_image_base64'], 'image/jpeg', 'image/png')
                ->usingFileName("face_attempt_{$attempt->id}.jpg")
                ->toMediaCollection('face-attempt-evidence');
        }

        return response()->json([
            'id' => $attempt->id,
            'suspicious' => $attempt->suspicious,
        ], 201);
    }

    private function compressedJpegBytes(string $base64Image): string
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

        return $jpegBytes;
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
