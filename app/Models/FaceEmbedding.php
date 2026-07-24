<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaceEmbedding extends Model
{
    protected $fillable = [
        'employee_id',
        'embedding',
        'embedding_revision',
        'image_hash',
        'pose_label',
        'model_name',
        'detector_backend',
        'quality',
    ];

    protected $casts = [
        'embedding' => 'array',
        'quality' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected static function booted(): void
    {
        static::saving(function (FaceEmbedding $faceEmbedding): void {
            if ($faceEmbedding->isDirty(['embedding', 'model_name'])) {
                $faceEmbedding->embedding_revision = max((int) $faceEmbedding->embedding_revision + 1, now()->getTimestamp());
            }
        });

        static::saved(function (FaceEmbedding $faceEmbedding): void {
            $faceEmbedding->employee?->forceFill([
                'auth_revision' => max((int) $faceEmbedding->employee->auth_revision + 1, (int) $faceEmbedding->embedding_revision),
            ])->saveQuietly();
        });
    }
}
