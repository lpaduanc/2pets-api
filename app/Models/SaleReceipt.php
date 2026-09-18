<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um recebimento de uma venda — contrato docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * É uma tabela filha (e não colunas em `sales`) porque a MESMA venda aceita várias formas:
 * "R$ 50,00 em dinheiro + R$ 80,00 no cartão" são dois recebimentos, com taxas e prazos de
 * liquidação diferentes. Critério de aceite: "Venda recebida em dinheiro + cartão na mesma
 * operação gera 2 `sale_receipts` e 2 movimentos de caixa".
 *
 * `operator_fee` e `expected_settlement_date` são congelados no momento do recebimento a
 * partir da forma cadastrada (doc 04) — a taxa vigente pode mudar amanhã, a que valeu aqui não.
 */
class SaleReceipt extends Model
{
    use HasFactory;

    protected $fillable = [
        'sale_id',
        'payment_method_id',
        'account_id',
        'amount',
        'installments',
        'received_at',
        'operator_fee',
        'net_amount',
        'expected_settlement_date',
        'received_by',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'operator_fee' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'installments' => 'integer',
            'received_at' => 'datetime',
            'expected_settlement_date' => 'date',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function settlementItems(): HasMany
    {
        return $this->hasMany(AcquirerSettlementItem::class);
    }

    /** Quanto deste recebimento já foi conciliado num depósito da adquirente (doc 04). */
    public function reconciledAmount(): float
    {
        return round((float) $this->settlementItems()->sum('amount'), 2);
    }

    public function isFullyReconciled(): bool
    {
        return abs($this->reconciledAmount() - (float) $this->net_amount) <= AcquirerSettlement::RECONCILIATION_TOLERANCE;
    }

    /**
     * Recebimentos que ainda esperam depósito da adquirente — a origem de
     * `AcquirerReconciliationService::expectedSettlements()`.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingSettlement(Builder $query): Builder
    {
        return $query->whereNotNull('expected_settlement_date')
            ->whereDoesntHave('settlementItems');
    }
}
