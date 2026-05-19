<?php

namespace App\Models;

use App\Enums\Attendance\AttendanceMethod;
use App\Enums\Attendance\OvertimeStatus;
use App\Enums\Attendance\Status;
use App\Enums\Attendance\Type;
use Carbon\Carbon;
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
        'attendance_type',
        'attendance_method',
        'offline_id',
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
        'location_source',
        'latitude',
        'longitude',
        'location_status',
        'zone_id',
        'remarks',
        'recorded_by',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'attendance_type' => Type::class,
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
        'attendance_method' => AttendanceMethod::class,
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attendance-image')->singleFile();
        $this->addMediaCollection('time-in-image')->singleFile();
        $this->addMediaCollection('time-out-image')->singleFile();
    }

    public function scopeIsLate(Builder $query)
    {
        return $query->where('is_late', true);
    }

    public function scopeIsUndertime(Builder $query)
    {
        return $query->where('is_undertime', false);
    }

    public function scopeIsOvertime(Builder $query)
    {
        return $query->where('is_overtime', true);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function dailyTotalHours(): ?float
    {
        $minutes = $this->dailyTotalMinutes();

        if ($minutes === null) {
            return null;
        }

        return round($minutes / 60, 2);
    }

    public function dailyTotalMinutes(): ?int
    {
        if (! $this->employee_id || ! $this->attendance_date) {
            return null;
        }

        $firstTimeIn = static::query()
            ->where('employee_id', $this->employee_id)
            ->whereDate('attendance_date', $this->attendance_date)
            ->whereNotNull('time_in')
            ->min('time_in');

        $lastTimeOut = static::query()
            ->where('employee_id', $this->employee_id)
            ->whereDate('attendance_date', $this->attendance_date)
            ->whereNotNull('time_out')
            ->max('time_out');

        if (! $firstTimeIn || ! $lastTimeOut) {
            return null;
        }

        return Carbon::parse($firstTimeIn)->diffInMinutes(Carbon::parse($lastTimeOut));
    }

    public function formattedDailyTotalHours(): string
    {
        $minutes = $this->dailyTotalMinutes();

        if ($minutes === null) {
            return '-';
        }

        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        if ($hours === 0) {
            return "{$remainingMinutes} min";
        }

        if ($remainingMinutes === 0) {
            return "{$hours}h";
        }

        return "{$hours}h {$remainingMinutes}m";
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
