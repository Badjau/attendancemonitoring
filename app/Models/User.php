<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'name',
        'username',
        'email',
        'password',
        'is_admin',
        'is_it_admin',
        'is_hr',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_admin' => 'boolean',
            'is_it_admin' => 'boolean',
            'is_hr' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function scopeWithoutMcasiaAdmin(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where('is_admin', false)
                ->orWhere(function (Builder $query): void {
                    $query
                        ->whereRaw('LOWER(name) != ?', ['mcasia admin'])
                        ->where(function (Builder $query): void {
                            $query
                                ->whereNull('username')
                                ->orWhereRaw('LOWER(username) NOT IN (?, ?, ?)', [
                                    'mcasia',
                                    'mcasia_admin',
                                    'mcasia-admin',
                                ]);
                        })
                        ->whereRaw('LOWER(email) NOT LIKE ?', ['mcasia@%'])
                        ->whereRaw('LOWER(email) NOT LIKE ?', ['mcasia_admin@%'])
                        ->whereRaw('LOWER(email) NOT LIKE ?', ['mcasia-admin@%']);
                });
        });
    }
}
