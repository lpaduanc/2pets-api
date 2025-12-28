<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'tier',
        'monthly_price',
        'yearly_price',
        'trial_days',
        'features',
        'limits',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'trial_days' => 'integer',
        'features' => 'array',
        'limits' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features);
    }

    public function getLimit(string $feature): int
    {
        return $this->limits[$feature] ?? 0;
    }

    public function isUnlimited(string $feature): bool
    {
        return ($this->limits[$feature] ?? 0) === -1;
    }
}

