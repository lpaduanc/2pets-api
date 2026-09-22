<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'invoice_id',
        // Fase 6 — sinal de agendamento (`purpose = deposit`): `invoice_id` fica `null`,
        // `appointment_id` aponta para o agendamento cobrado.
        'appointment_id',
        'user_id',
        'gateway',
        'gateway_payment_id',
        'purpose',
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

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
