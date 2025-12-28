<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    protected $fillable = [
        'professional_id',
        'transaction_type',
        'transaction_id',
        'transaction_amount',
        'commission_rate',
        'commission_amount',
        'status',
        'payout_id',
    ];

    protected $casts = [
        'transaction_amount' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'commission_amount' => 'decimal:2',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function approve(): void
    {
        $this->update(['status' => 'approved']);
    }

    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);
    }
}

