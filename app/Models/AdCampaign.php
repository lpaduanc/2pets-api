<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdCampaign extends Model
{
    protected $fillable = [
        'professional_id',
        'campaign_type',
        'title',
        'description',
        'image_url',
        'target_url',
        'daily_budget',
        'total_spent',
        'start_date',
        'end_date',
        'status',
        'targeting',
    ];

    protected $casts = [
        'daily_budget' => 'decimal:2',
        'total_spent' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'targeting' => 'array',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function impressions(): HasMany
    {
        return $this->hasMany(AdImpression::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(AdClick::class);
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->start_date->isFuture()) {
            return false;
        }

        if ($this->end_date && $this->end_date->isPast()) {
            return false;
        }

        return true;
    }

    public function incrementSpent(float $amount): void
    {
        $this->increment('total_spent', $amount);

        if ($this->total_spent >= $this->getTotalBudget()) {
            $this->update(['status' => 'completed']);
        }
    }

    public function getTotalBudget(): float
    {
        if (!$this->end_date) {
            return PHP_FLOAT_MAX;
        }

        $days = $this->start_date->diffInDays($this->end_date) + 1;
        return $this->daily_budget * $days;
    }

    public function getCtr(): float
    {
        $impressionCount = $this->impressions()->count();
        $clickCount = $this->clicks()->count();

        return $impressionCount > 0 ? ($clickCount / $impressionCount) * 100 : 0;
    }
}

