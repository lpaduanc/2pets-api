<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalRecord extends Model
{
    protected $fillable = [
        'pet_id',
        'professional_id',
        'appointment_id',
        'record_date',
        'weight',
        'temperature',
        'heart_rate',
        'respiratory_rate',
        'subjective',
        'objective',
        'assessment',
        'plan',
        'symptoms',
        'diagnosis',
        'treatment_plan',
        'prescriptions',
        'notes',
    ];

    protected $casts = [
        'record_date' => 'date',
        'weight' => 'decimal:2',
        'temperature' => 'decimal:1',
        'heart_rate' => 'integer',
        'respiratory_rate' => 'integer',
        'symptoms' => 'array',
        'prescriptions' => 'array',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }
}
