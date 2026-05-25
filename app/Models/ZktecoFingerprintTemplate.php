<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZktecoFingerprintTemplate extends Model
{
    protected $fillable = [
        'employee_id',
        'finger_index',
        'template_base64',
        'template_format',
        'device_serial',
        'template_size',
        'fingerprint_image_base64',
        'enrolled_at',
    ];

    protected $casts = [
        'finger_index' => 'integer',
        'template_size' => 'integer',
        'enrolled_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
