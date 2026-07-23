<?php

namespace App\Models;

use App\Services\FaceServiceClient;
use App\Services\KioskAuthSyncService;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Hash;
use Laragear\WebAuthn\Contracts\WebAuthnAuthenticatable;
use Laragear\WebAuthn\WebAuthnAuthentication;
use Laragear\WebAuthn\WebAuthnData;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Employee extends Model implements HasMedia, WebAuthnAuthenticatable
{
    use InteractsWithMedia;
    use WebAuthnAuthentication;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_EMPLOYEE = 'employee';

    protected $fillable = [
        'department_id',
        'branch',
        'employee_id',
        'rfid_uid',
        'password',
        'kiosk_pin_verifier',
        'first_name',
        'last_name',
        'middle_name',
        'date_of_birth',
        'position',
        'role',
        'auth_revision',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'password' => 'hashed',
    ];

    protected $hidden = ['password'];

    protected $appends = ['name', 'branch'];

    protected static function booted(): void
    {
        static::saving(function (Employee $employee): void {
            if ($employee->isDirty([
                'employee_id',
                'rfid_uid',
                'password',
                'kiosk_pin_verifier',
                'first_name',
                'last_name',
                'middle_name',
                'position',
                'role',
            ])) {
                $employee->auth_revision = max((int) $employee->auth_revision + 1, now()->getTimestamp());
            }
        });

        static::deleted(function (Employee $employee): void {
            if (filled($employee->employee_id)) {
                rescue(
                    fn () => app(FaceServiceClient::class)->deleteEmployeeCache($employee->employee_id),
                    report: true,
                );
            }
        });
    }

    protected function password(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): array {
                if (! filled($value)) {
                    return [];
                }

                return [
                    'password' => Hash::make($value),
                    'kiosk_pin_verifier' => app(KioskAuthSyncService::class)->pinVerifier($value),
                ];
            },
        );
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('employee-profile')->useDisk('public')->singleFile();
    }

    public function employeeProfileUrl(): string
    {
        $url = trim($this->getFirstMediaUrl('employee-profile'));

        return $url === '' ? '' : str_replace('\\', '/', $url);
    }

    public function getNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    protected function branch(): Attribute
    {
        return Attribute::get(function (?string $value): string {
            if ($this->relationLoaded('branches')) {
                return $this->primaryBranch()?->name
                    ?? $this->branches->first()?->name
                    ?? (string) $value;
            }

            return (string) ($this->primaryBranch()->name ?? $value ?? '');
        });
    }

    public function branchNames(): string
    {
        $branches = $this->relationLoaded('branches')
            ? $this->branches
            : $this->branches()->get();

        return $branches
            ->sortByDesc(fn (Branch $branch): bool => (bool) $branch->pivot?->is_primary)
            ->pluck('name')
            ->implode(', ');
    }

    public function primaryBranch(): ?Branch
    {
        if ($this->relationLoaded('branches')) {
            return $this->branches->firstWhere('pivot.is_primary', true)
                ?? $this->branches->first();
        }

        return $this->branches()
            ->wherePivot('is_primary', true)
            ->first()
            ?? $this->branches()->first();
    }

    public static function branchOptions(): array
    {
        return Branch::query()
            ->active()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function roleOptions(): array
    {
        return [
            self::ROLE_EMPLOYEE => 'User Employee',
            self::ROLE_ADMIN => 'Admin',
        ];
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

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function timeclockAuthorization(): HasOne
    {
        return $this->hasOne(TimeclockAuthorizedUser::class);
    }

    public function zktecoFingerprintTemplates(): HasMany
    {
        return $this->hasMany(ZktecoFingerprintTemplate::class);
    }

    public function faceEmbeddings(): HasMany
    {
        return $this->hasMany(FaceEmbedding::class);
    }

    public function latestZktecoFingerprintTemplate(): HasOne
    {
        return $this->hasOne(ZktecoFingerprintTemplate::class)->latestOfMany('enrolled_at');
    }
}
