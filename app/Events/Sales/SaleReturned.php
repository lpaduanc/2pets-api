<?php

namespace App\Events\Sales;

use App\Contracts\AffectsSaleProfitability;
use Illuminate\Foundation\Events\Dispatchable;

/** Itens da venda foram devolvidos. Recalcula a rentabilidade (PLANO 1.5). */
final class SaleReturned implements AffectsSaleProfitability
{
    use Dispatchable;

    public function __construct(public readonly int $saleId) {}

    public function saleId(): int
    {
        return $this->saleId;
    }
}
