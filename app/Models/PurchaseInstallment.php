<?php

namespace App\Models;

use App\Enums\PurchaseInstallmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Parcela a pagar da compra — ponte até `accounts_payable` (doc 03); ver a migration. */
class PurchaseInstallment extends Model
{
    protected $fillable = [
        'purchase_id',
        'number',
        'due_date',
        'amount',
        'payment_method_id',
        'financial_account_id',
        'status',
        'paid_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseInstallmentStatus::class,
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'number' => 'integer',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }
}
