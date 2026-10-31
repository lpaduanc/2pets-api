<?php

namespace App\Events\Sales;

use App\Contracts\AffectsSaleProfitability;
use Illuminate\Foundation\Events\Dispatchable;

/** A comissão de itens da venda foi fechada/recalculada. Recalcula a rentabilidade (PLANO 1.5). */
final class CommissionRecalculated implements AffectsSaleProfitability
{
    use Dispatchable;

    public function __construct(public readonly int $saleId) {}

    public function saleId(): int
    {
        return $this->saleId;
    }
}
