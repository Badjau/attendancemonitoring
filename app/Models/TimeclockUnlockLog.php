<?php

namespace App\Models;

use App\Enums\Attendance\AttendanceMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class TimeclockUnlockLog extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'timeclock_authorized_user_id',
        'method',
        'ip_address',
        'user_agent',
        'unlocked_at',
    ];

    protected $casts = [
        'unlocked_at' => 'datetime',
        'method' => AttendanceMethod::class,
    ];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('unlock-audit-image')->singleFile();
    }

    public function authorizedUser(): BelongsTo
    {
        return $this->belongsTo(TimeclockAuthorizedUser::class, 'timeclock_authorized_user_id');
    }
}
