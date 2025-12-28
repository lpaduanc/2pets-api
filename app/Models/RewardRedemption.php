<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RewardRedemption extends Model
{
    protected $fillable = [
        'loyalty_account_id',
        'reward_id',
        'points_spent',
        'status',
        'redemption_code',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'points_spent' => 'integer',
        'expires_at' => 'date',
        'used_at' => 'date',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($redemption) {
            if (empty($redemption->redemption_code)) {
                $redemption->redemption_code = strtoupper(Str::random(10));
            }
        });
    }

    public function loyaltyAccount(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class);
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(Reward::class);
    }

    public function markAsUsed(): void
    {
        $this->update([
            'status' => 'completed',
            'used_at' => now(),
        ]);
    }
}

