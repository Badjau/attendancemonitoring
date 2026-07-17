<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Branch extends Model
{
    protected $fillable = [
        'name',
        'code',
        'latitude',
        'longitude',
        'radius_meters',
        'is_active',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'radius_meters' => 'float',
        'is_active' => 'boolean',
    ];

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasCoordinates(): bool
    {
        return is_float($this->latitude)
            && is_float($this->longitude)
            && ($this->latitude !== 0.0 || $this->longitude !== 0.0);
    }
}
