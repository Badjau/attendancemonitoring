<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class FaceAttempt extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'employee_id',
        'attendance_id',
        'candidate_employee_identifier',
        'decision',
        'reason_code',
        'match_score',
        'liveness_score',
        'quality_score',
        'risk_score',
        'frame_count',
        'usable_frame_count',
        'matched_frame_count',
        'fallback_used',
        'suspicious',
        'device_id',
        'session_id',
        'ip_address',
        'user_agent',
        'metadata',
        'attempted_at',
    ];

    protected $casts = [
        'match_score' => 'float',
        'liveness_score' => 'float',
        'quality_score' => 'float',
        'risk_score' => 'float',
        'frame_count' => 'integer',
        'usable_frame_count' => 'integer',
        'matched_frame_count' => 'integer',
        'fallback_used' => 'boolean',
        'suspicious' => 'boolean',
        'metadata' => 'array',
        'attempted_at' => 'datetime',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('face-attempt-evidence')->singleFile();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}
