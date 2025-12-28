<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Availability extends Model
{
    protected $fillable = [
        'professional_id',
        'day_of_week',
        'start_time',
        'end_time',
        'slot_duration',
        'buffer_time',
        'is_active',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'slot_duration' => 'integer',
        'buffer_time' => 'integer',
        'is_active' => 'boolean',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }
}

