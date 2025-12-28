<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionUsage extends Model
{
    protected $fillable = [
        'subscription_id',
        'feature',
        'used',
        'limit',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'used' => 'integer',
        'limit' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isWithinLimit(): bool
    {
        if ($this->limit === -1) {
            return true; // Unlimited
        }

        return $this->used < $this->limit;
    }

    public function getRemainingUsage(): int
    {
        if ($this->limit === -1) {
            return -1; // Unlimited
        }

        return max(0, $this->limit - $this->used);
    }

    public function incrementUsage(int $amount = 1): void
    {
        $this->increment('used', $amount);
    }
}

