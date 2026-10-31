<?php

namespace App\Events\Sales;

use App\Contracts\AffectsSaleProfitability;
use Illuminate\Foundation\Events\Dispatchable;

/** A venda ficou paga — pelo recebimento que quita ou pelo `finalize` sem pagamento (PLANO 1.4). Recalcula a rentabilidade (PLANO 1.5). */
final class SaleFinalized implements AffectsSaleProfitability
{
    use Dispatchable;

    public function __construct(public readonly int $saleId) {}

    public function saleId(): int
    {
        return $this->saleId;
    }
}
