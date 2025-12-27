<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    protected $fillable = [
        'professional_id',
        'client_id',
        'pet_id',
        'appointment_date',
        'appointment_time',
        'duration',
        'type',
        'status',
        'reason',
        'notes',
        'price',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'appointment_time' => 'datetime:H:i',
        'duration' => 'integer',
        'price' => 'decimal:2',
    ];

    // Relationships
    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function medicalRecords()
    {
        return $this->hasMany(MedicalRecord::class);
    }

    public function prescriptions()
    {
        return $this->hasMany(Prescription::class);
    }

    public function vaccinations()
    {
        return $this->hasMany(Vaccination::class);
    }

    // Scopes
    public function scopeToday($query)
    {
        return $query->whereDate('appointment_date', today());
    }

    public function scopeUpcoming($query)
    {
        return $query->where('appointment_date', '>=', today())
            ->whereIn('status', ['scheduled', 'confirmed']);
    }

    public function scopeForProfessional($query, $professionalId)
    {
        return $query->where('professional_id', $professionalId);
    }
}
