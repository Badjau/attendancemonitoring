<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Employee extends Model implements HasMedia
{
    use InteractsWithMedia;

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

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('employee-profile')->singleFile();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
