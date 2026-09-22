<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha de fechamento de comissão — um `sale_item` congelado num valor de comissão. Contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md.
 *
 * `unique(sale_item_id)` no banco garante que um item de venda nunca entra em dois
 * fechamentos — a mesma sessão de banho não gera comissão duas vezes.
 */
class CommissionSettlementItem extends Model
{
    protected $fillable = [
        'commission_settlement_id',
        'sale_item_id',
        'commission_rule_id',
        'base_amount',
        'commission_amount',
    ];

    protected $casts = [
        'base_amount' => 'decimal:2',
        'commission_amount' => 'decimal:2',
    ];

    public function commissionSettlement(): BelongsTo
    {
        return $this->belongsTo(CommissionSettlement::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function commissionRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class);
    }
}
