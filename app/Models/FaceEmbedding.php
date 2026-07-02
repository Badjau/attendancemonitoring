<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaceEmbedding extends Model
{
    protected $fillable = [
        'employee_id',
        'embedding',
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
}
