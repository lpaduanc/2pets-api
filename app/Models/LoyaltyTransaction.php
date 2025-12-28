<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends Model
{
    protected $fillable = [
        'loyalty_account_id',
        'type',
        'points',
        'description',
        'reference_type',
        'reference_id',
        'expires_at',
    ];

    protected $casts = [
        'points' => 'integer',
        'expires_at' => 'date',
    ];

    public function loyaltyAccount(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class);
    }
}

