<?php

namespace App\Events\Sales;

use App\Contracts\AffectsSaleProfitability;
use Illuminate\Foundation\Events\Dispatchable;

/** Um recebimento foi estornado (cancelamento da venda). Recalcula a rentabilidade (PLANO 1.5). */
final class SaleReceiptCancelled implements AffectsSaleProfitability
{
    use Dispatchable;

    public function __construct(public readonly int $saleId) {}

    public function saleId(): int
    {
        return $this->saleId;
    }
}
