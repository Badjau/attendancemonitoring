<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class AttendanceBreak extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'attendance_id',
        'employee_id',
        'attendance_date',
        'sequence_number',
        'break_policy_type',
        'allowed_minutes',
        'started_at',
        'ended_at',
        'duration_minutes',
        'exceeded_minutes',
        'closed_by_time_out',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'sequence_number' => 'integer',
        'allowed_minutes' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_minutes' => 'integer',
        'exceeded_minutes' => 'integer',
        'closed_by_time_out' => 'boolean',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('break-start-images');
        $this->addMediaCollection('break-end-images');
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
