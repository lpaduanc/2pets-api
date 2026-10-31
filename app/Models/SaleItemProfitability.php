<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resultado de uma linha de venda finalizada (docs/gap-petflow/PLANO.md, 1.5). Escrito só por
 * `SaleProfitabilityService`; relatório lê daqui e nunca recalcula com o cadastro de hoje.
 */
class SaleItemProfitability extends Model
{
    /** Colunas de dinheiro, na ordem do quadro de resultado. */
    public const MONEY_COLUMNS = [
        'gross_total', 'allocated_header_discount', 'net_revenue', 'cost_amount',
        'card_fee_share', 'tax_amount', 'commission_amount', 'contribution_margin',
    ];

    protected $table = 'sale_item_profitability';

    protected $fillable = [
        'organization_id',
        'professional_id',
        'sale_id',
        'sale_item_id',
        'quantity',
        ...self::MONEY_COLUMNS,
        'computed_at',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_fill_keys(self::MONEY_COLUMNS, 'decimal:2') + [
            'quantity' => 'decimal:4',
            'computed_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
