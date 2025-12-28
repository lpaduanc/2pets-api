<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    protected $fillable = [
        'referrer_id',
        'referred_id',
        'referral_code',
        'status',
        'points_awarded',
        'completed_at',
    ];

    protected $casts = [
        'points_awarded' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    public function complete(int $points): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        // Award points will be handled by LoyaltyService
    }
}

