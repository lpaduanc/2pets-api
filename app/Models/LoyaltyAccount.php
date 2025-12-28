<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyAccount extends Model
{
    protected $fillable = [
        'user_id',
        'points_balance',
        'tier',
        'lifetime_points',
        'tier_expires_at',
    ];

    protected $casts = [
        'points_balance' => 'integer',
        'lifetime_points' => 'integer',
        'tier_expires_at' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(RewardRedemption::class);
    }

    public function addPoints(int $points, string $description, string $type = 'earned', array $reference = null): void
    {
        $this->increment('points_balance', $points);
        $this->increment('lifetime_points', $points);

        $this->transactions()->create([
            'type' => $type,
            'points' => $points,
            'description' => $description,
            'reference_type' => $reference['type'] ?? null,
            'reference_id' => $reference['id'] ?? null,
            'expires_at' => now()->addYear(),
        ]);

        $this->updateTier();
    }

    public function deductPoints(int $points, string $description): bool
    {
        if ($this->points_balance < $points) {
            return false;
        }

        $this->decrement('points_balance', $points);

        $this->transactions()->create([
            'type' => 'redeemed',
            'points' => -$points,
            'description' => $description,
        ]);

        return true;
    }

    private function updateTier(): void
    {
        $newTier = match (true) {
            $this->lifetime_points >= 5000 => 'platinum',
            $this->lifetime_points >= 2000 => 'gold',
            $this->lifetime_points >= 500 => 'silver',
            default => 'bronze',
        };

        if ($this->tier !== $newTier) {
            $this->update([
                'tier' => $newTier,
                'tier_expires_at' => now()->addYear(),
            ]);
        }
    }
}

