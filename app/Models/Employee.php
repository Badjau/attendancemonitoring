<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laragear\WebAuthn\WebAuthnData;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Employee extends Model implements HasMedia, WebAuthnAuthenticatable
{
    use InteractsWithMedia;
    use WebAuthnAuthentication;

    protected $fillable = [
        'department_id',
        'employee_id',
        'first_name',
        'last_name',
        'middle_name',
        'date_of_birth',
        'position',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    protected $appends = ['name'];

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('employee-profile')->singleFile();
    }

    public function getNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function webAuthnData(): WebAuthnData
    {
        return WebAuthnData::make($this->employee_id, $this->name);
    }

    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class, 'zone_employee')
            ->withPivot(['is_temporary', 'effective_date', 'expiry_date'])
            ->withTimestamps();
    }

    public function activeZones(): BelongsToMany
    {
        return $this->zones()
            ->where('zones.is_active', true)
            ->where(function ($q) {
                $q->whereNull('zone_employee.effective_date')
                    ->orWhere('zone_employee.effective_date', '<=', today());
            })
            ->where(function ($q) {
                $q->whereNull('zone_employee.expiry_date')
                    ->orWhere('zone_employee.expiry_date', '>=', today());
            });
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }
}
