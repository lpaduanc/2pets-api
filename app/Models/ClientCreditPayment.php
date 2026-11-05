<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Um recebimento de crediário (PLANO 2.2) e o seu rateio FIFO por parcela. */
class ClientCreditPayment extends Model
{
    protected $fillable = [
        'organization_id', 'professional_id', 'client_id', 'payment_method_id', 'paid_on', 'amount',
        'principal_amount', 'fees_amount', 'fee_discount', 'financial_entry_id', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount' => 'decimal:2', 'principal_amount' => 'decimal:2', 'fees_amount' => 'decimal:2', 'fee_discount' => 'decimal:2'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ClientCreditPaymentItem::class);
    }
}
