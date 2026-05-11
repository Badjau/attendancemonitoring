<?php

namespace App\Models;

use App\Enums\Zones\Policy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Zone extends Model
{
    protected $fillable = [
        'name',
        'latitude',
        'longitude',
        'location',
        'radius_meters',
        'policy',
        'is_active',
    ];

    protected $appends = [
        'location',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'radius_meters' => 'float',
        'policy' => Policy::class,
        'is_active' => 'boolean',
    ];

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'zone_employee')
            ->withPivot(['is_temporary', 'effective_date', 'expiry_date'])
            ->withTimestamps();
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function getLocationAttribute(): ?array
    {
        if ($this->latitude === null || $this->longitude === null) {
            return null;
        }

        return [
            'lat' => (float) $this->latitude,
            'lng' => (float) $this->longitude,
        ];
    }

    public function setLocationAttribute(?array $location): void
    {
        if (! is_array($location)) {
            return;
        }

        $this->attributes['latitude'] = $location['lat'] ?? $location['latitude'] ?? null;
        $this->attributes['longitude'] = $location['lng'] ?? $location['longitude'] ?? null;
    }

    public function scopeIsActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
