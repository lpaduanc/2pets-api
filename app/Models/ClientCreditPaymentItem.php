<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quanto de um recebimento de crediário foi para uma parcela (PLANO 2.2). */
class ClientCreditPaymentItem extends Model
{
    protected $fillable = ['client_credit_payment_id', 'client_credit_installment_id', 'principal', 'fees', 'fee_discount', 'sale_receipt_id', 'reversed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['principal' => 'decimal:2', 'fees' => 'decimal:2', 'fee_discount' => 'decimal:2', 'reversed_at' => 'datetime'];
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(ClientCreditInstallment::class, 'client_credit_installment_id');
    }
}
