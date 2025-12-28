<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payout extends Model
{
    protected $fillable = [
        'professional_id',
        'total_amount',
        'commission_count',
        'status',
        'payment_method',
        'payment_details',
        'payment_reference',
        'paid_at',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'commission_count' => 'integer',
        'payment_details' => 'array',
        'paid_at' => 'date',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    public function markAsPaid(string $reference): void
    {
        $this->update([
            'status' => 'paid',
            'payment_reference' => $reference,
            'paid_at' => now(),
        ]);

        $this->commissions()->update(['status' => 'paid']);
    }

    public function markAsFailed(string $reason): void
    {
        $this->update([
            'status' => 'failed',
            'notes' => $reason,
        ]);
    }
}

