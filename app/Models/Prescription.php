<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prescription extends Model
{
    protected $fillable = [
        'pet_id',
        'professional_id',
        'appointment_id',
        'medical_record_id',
        'prescription_date',
        'valid_until',
        'medications',
        'general_instructions',
        'warnings',
        'is_controlled',
    ];

    protected $casts = [
        'prescription_date' => 'date',
        'valid_until' => 'date',
        'medications' => 'array',
        'is_controlled' => 'boolean',
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

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    public function scopeValid($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('valid_until')
                ->orWhere('valid_until', '>=', today());
        });
    }
}
