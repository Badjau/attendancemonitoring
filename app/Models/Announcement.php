<?php

namespace App\Models;

use App\Enums\Announcement\Status;
use App\Enums\Announcement\Type;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Announcement extends Model implements HasMedia
{
    use InteractsWithMedia;
    use SoftDeletes;

    public const HOME_CACHE_KEY = 'announcements_home_visible_v2';

    protected $fillable = [
        'title',
        'content',
        'type',
        'status',
        'created_by',
        'published_at',
        'expires_at',
        'is_pinned',
    ];

    protected $casts = [
        'published_at' => 'timestamp',
        'expires_at' => 'timestamp',
        'is_pinned' => 'boolean',
        'type' => Type::class,
        'status' => Status::class,
    ];

    protected static function booted(): void
    {
        $forgetHomeCache = fn (): bool => Cache::forget(self::HOME_CACHE_KEY);

        static::saved($forgetHomeCache);
        static::deleted($forgetHomeCache);
        static::restored($forgetHomeCache);
        static::forceDeleted($forgetHomeCache);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('announcement_attachments')
            ->useDisk('public');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', Status::PUBLISHED->value)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('published_at')
                    ->orWhere('published_at', '<=', Carbon::now());
            });
    }

    public function scopeIsPinned(Builder $query): Builder
    {
        return $query->where('is_pinned', true);
    }

    public function scopeIsNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNull('expires_at')
                ->orWhereDate('expires_at', '>=', Carbon::today());
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
