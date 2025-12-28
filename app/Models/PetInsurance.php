<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PetInsurance extends Model
{
    protected $fillable = [
        'pet_id',
        'user_id',
        'insurance_provider_id',
        'policy_number',
        'coverage_type',
        'monthly_premium',
        'deductible',
        'annual_limit',
        'start_date',
        'end_date',
        'status',
        'coverage_details',
        'exclusions',
    ];

    protected $casts = [
        'monthly_premium' => 'decimal:2',
        'deductible' => 'decimal:2',
        'annual_limit' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'coverage_details' => 'array',
        'exclusions' => 'array',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(InsuranceProvider::class, 'insurance_provider_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(InsuranceClaim::class);
    }

    public function preAuthorizations(): HasMany
    {
        return $this->hasMany(InsurancePreAuthorization::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' 
            && $this->start_date->isPast() 
            && (!$this->end_date || $this->end_date->isFuture());
    }

    public function getRemainingAnnualLimit(): float
    {
        if (!$this->annual_limit) {
            return PHP_FLOAT_MAX;
        }

        $totalApproved = $this->claims()
            ->whereYear('created_at', now()->year)
            ->whereIn('status', ['approved', 'paid'])
            ->sum('approved_amount');

        return max(0, $this->annual_limit - $totalApproved);
    }
}

