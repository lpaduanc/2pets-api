<?php

namespace App\Events\Sales;

use App\Contracts\AffectsSaleProfitability;
use Illuminate\Foundation\Events\Dispatchable;

/** Um recebimento entrou na venda (taxa da adquirente nova). Recalcula a rentabilidade (PLANO 1.5). */
final class SaleReceiptAdded implements AffectsSaleProfitability
{
    use Dispatchable;

    public function __construct(public readonly int $saleId) {}

    public function saleId(): int
    {
        return $this->saleId;
    }
}
