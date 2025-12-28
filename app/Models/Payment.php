<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'invoice_id',
        'user_id',
        'gateway',
        'gateway_payment_id',
        'method',
        'amount',
        'status',
        'installments',
        'paid_at',
        'expires_at',
        'gateway_response',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'installments' => 'integer',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
        'gateway_response' => 'array',
        'metadata' => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

