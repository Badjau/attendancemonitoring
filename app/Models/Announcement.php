<?php

namespace App\Models;

use App\Enums\Announcement\Status;
use App\Enums\Announcement\Type;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Announcement extends Model implements HasMedia
{
    use InteractsWithMedia;
    use SoftDeletes;

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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('announcement_attachments');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', Status::PUBLISHED->value);
    }

    public function scopeIsPinned(Builder $query): Builder
    {
        return $query->where('is_pinned', true);
    }

    public function scopeIsNotExpired(Builder $query): Builder
    {
        return $query->whereDate('expires_at', '>', Carbon::now());
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
