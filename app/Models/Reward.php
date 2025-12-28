<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reward extends Model
{
    protected $fillable = [
        'name',
        'description',
        'points_cost',
        'reward_type',
        'reward_data',
        'stock',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'points_cost' => 'integer',
        'reward_data' => 'array',
        'stock' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function redemptions(): HasMany
    {
        return $this->hasMany(RewardRedemption::class);
    }

    public function isAvailable(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->stock !== null && $this->stock <= 0) {
            return false;
        }

        return true;
    }

    public function decrementStock(): void
    {
        if ($this->stock !== null) {
            $this->decrement('stock');
        }
    }
}

