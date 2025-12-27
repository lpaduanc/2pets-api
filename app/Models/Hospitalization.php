<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Hospitalization extends Model
{
    protected $fillable = [
        'pet_id',
        'professional_id',
        'admission_date',
        'discharge_date',
        'reason',
        'status',
        'daily_notes',
        'medications',
        'total_cost',
    ];

    protected $casts = [
        'admission_date' => 'date',
        'discharge_date' => 'date',
        'daily_notes' => 'array',
        'medications' => 'array',
        'total_cost' => 'decimal:2',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }
}
