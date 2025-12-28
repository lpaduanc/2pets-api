<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InsuranceClaim extends Model
{
    protected $fillable = [
        'pet_insurance_id',
        'appointment_id',
        'claim_number',
        'claim_type',
        'description',
        'claimed_amount',
        'approved_amount',
        'reimbursed_amount',
        'status',
        'documents',
        'rejection_reason',
        'incident_date',
        'submitted_at',
        'processed_at',
    ];

    protected $casts = [
        'claimed_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'reimbursed_amount' => 'decimal:2',
        'documents' => 'array',
        'incident_date' => 'date',
        'submitted_at' => 'date',
        'processed_at' => 'date',
    ];

    public function petInsurance(): BelongsTo
    {
        return $this->belongsTo(PetInsurance::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function submit(): void
    {
        $this->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
    }

    public function approve(float $approvedAmount): void
    {
        $this->update([
            'status' => 'approved',
            'approved_amount' => $approvedAmount,
            'processed_at' => now(),
        ]);
    }

    public function reject(string $reason): void
    {
        $this->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'processed_at' => now(),
        ]);
    }
}

