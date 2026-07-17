<?php

namespace App\Services;

use App\Enums\Attendance\AttendanceMethod;
use App\Models\Employee;
use App\Models\FaceEmbedding;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class KioskAuthSyncService
{
    private const FACE_MODEL_VERSION = 'SFace';

    public function manifest(): array
    {
        $revision = $this->currentRevision();

        return [
            'current_revision' => $revision,
            'generated_at' => now('Asia/Manila')->toIso8601String(),
            'employee_count' => Employee::query()->count(),
            'cache_policy' => [
                'fresh_seconds' => 24 * 60 * 60,
                'stale_seconds' => 7 * 24 * 60 * 60,
                'expired_allows_attendance' => true,
            ],
            'hash' => [
                'algorithm' => 'sha256',
                'salt' => $this->hashSalt(),
            ],
            'checksum' => hash('sha256', $revision.'|'.Employee::query()->max('updated_at')),
            'face_model_version' => self::FACE_MODEL_VERSION,
        ];
    }

    public function fullPayload(): array
    {
        return [
            'manifest' => $this->manifest(),
            'records' => $this->records(Employee::query()
                ->with('faceEmbeddings')
                ->orderBy('id')
                ->get()),
            'tombstones' => [],
        ];
    }

    public function incrementalPayload(int $sinceRevision): array
    {
        $employees = Employee::query()
            ->with('faceEmbeddings')
            ->where(function ($query) use ($sinceRevision): void {
                $query
                    ->where('auth_revision', '>', $sinceRevision)
                    ->orWhereHas('faceEmbeddings', fn ($faceQuery) => $faceQuery->where('embedding_revision', '>', $sinceRevision));
            })
            ->orderBy('id')
            ->get();

        return [
            'manifest' => $this->manifest(),
            'records' => $this->records($employees),
            'tombstones' => [],
        ];
    }

    public function rfidHash(?string $rfid): ?string
    {
        $normalized = $this->normalizeIdentifier($rfid);

        return $normalized === '' ? null : hash('sha256', $normalized.'|'.$this->hashSalt());
    }

    public function pinVerifier(?string $pin): ?string
    {
        $normalized = trim((string) $pin);

        return $normalized === '' ? null : hash('sha256', $normalized.'|'.$this->hashSalt());
    }

    public function currentRevision(): int
    {
        $employeeRevision = (int) Employee::query()->max('auth_revision');
        $faceRevision = (int) FaceEmbedding::query()->max('embedding_revision');

        return max(1, $employeeRevision, $faceRevision);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     */
    private function records(Collection $employees): array
    {
        return $employees
            ->map(fn (Employee $employee): array => $this->record($employee))
            ->values()
            ->all();
    }

    private function record(Employee $employee): array
    {
        $employee->loadMissing('faceEmbeddings');

        $authMethods = [AttendanceMethod::FACE->value];
        if (filled($employee->rfid_uid)) {
            $authMethods[] = AttendanceMethod::RFID->value;
        }
        if (filled($employee->kiosk_pin_verifier)) {
            $authMethods[] = AttendanceMethod::KEYPAD->value;
        }

        $faceRevision = (int) ($employee->faceEmbeddings->max('embedding_revision') ?? $employee->auth_revision);

        return [
            'employee_id' => $employee->id,
            'employee_number' => $employee->employee_id,
            'display_name' => $employee->name,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'position' => $employee->position,
            'branch' => $employee->branch,
            'active' => true,
            'allowed_auth_methods' => array_values(array_unique($authMethods)),
            'auth_revision' => (int) $employee->auth_revision,
            'updated_at' => Carbon::parse($employee->updated_at)->toIso8601String(),
            'deleted_at' => null,
            'revoked' => false,
            'rfid_hashes' => collect([$this->rfidHash($employee->rfid_uid)])->filter()->values()->all(),
            'keypad_pin_hash' => $employee->kiosk_pin_verifier,
            'face_embedding_revision' => $faceRevision,
            'face_model_version' => self::FACE_MODEL_VERSION,
            'face_embeddings' => $employee->faceEmbeddings
                ->map(fn ($embedding): array => [
                    'id' => $embedding->id,
                    'vector' => $embedding->embedding,
                    'model_version' => $embedding->model_name ?: self::FACE_MODEL_VERSION,
                    'revision' => (int) $embedding->embedding_revision,
                    'active' => true,
                    'revoked' => false,
                ])
                ->values()
                ->all(),
        ];
    }

    private function normalizeIdentifier(?string $identifier): string
    {
        return trim((string) preg_replace('/[[:cntrl:]]/', '', (string) $identifier));
    }

    private function hashSalt(): string
    {
        return (string) config('services.kiosk_auth.hash_salt');
    }
}
