<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsurancePreAuthorization extends Model
{
    protected $fillable = [
        'pet_insurance_id',
        'appointment_id',
        'authorization_number',
        'procedure_type',
        'procedure_description',
        'estimated_cost',
        'approved_amount',
        'status',
        'valid_until',
        'notes',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'valid_until' => 'date',
    ];

    public function petInsurance(): BelongsTo
    {
        return $this->belongsTo(PetInsurance::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function isValid(): bool
    {
        return $this->status === 'approved' 
            && $this->valid_until 
            && $this->valid_until->isFuture();
    }

    public function approve(float $approvedAmount, \DateTime $validUntil): void
    {
        $this->update([
            'status' => 'approved',
            'approved_amount' => $approvedAmount,
            'valid_until' => $validUntil,
        ]);
    }

    public function deny(string $reason): void
    {
        $this->update([
            'status' => 'denied',
            'notes' => $reason,
        ]);
    }
}

