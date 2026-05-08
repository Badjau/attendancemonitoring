<?php

namespace App\Models;

use App\Enums\Attendance\OvertimeStatus;
use App\Enums\Attendance\Status;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Attendance extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'employee_id',
        'rfid_uid',
        'attendance_date',
        'time_in',
        'time_out',
        'total_hours',
        'status',
        'is_late',
        'late_minutes',
        'is_undertime',
        'undertime_minutes',
        'is_overtime',
        'overtime_minutes',
        'overtime_status',
        'location',
        'latitude',
        'longitude',
        'location_status',
        'zone_id',
        'remarks',
        'recorded_by'
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'time_in' => 'timestamp',
        'time_out' => 'timestamp',
        'total_hours' => 'decimal:5',
        'status' => Status::class,
        'is_late' => 'boolean',
        'late_minutes' => 'integer',
        'is_undertime' => 'boolean',
        'undertime_minutes' => 'integer',
        'is_overtime' => 'boolean',
        'overtime_minutes' => 'integer',
        'overtime_status' => OvertimeStatus::class,
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attendance-image')->singleFile();
        $this->addMediaCollection('time-in-image')->singleFile();
        $this->addMediaCollection('time-out-image')->singleFile();
    }

    public function scopeIsLate(Builder $query)
    {
        if (!$query) {
            return $query;
        }

        return $query->where('is_late', true);
    }

    public function scopeIsUndertime(Builder $query)
    {
        if (!$query) {
            return $query;
        }

        return $query->where('is_undertime', false);
    }

    public function scopeIsOvertime(Builder $query)
    {
        if (!$query) {
            return $query;
        }

        return $query->where('is_overtime', true);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
