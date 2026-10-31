<?php

namespace App\Contracts;

/**
 * Evento que muda o resultado de uma venda (PLANO 1.5) — o `RecomputeSaleProfitability` escuta
 * todos pelo mesmo contrato e recalcula a venda `saleId`.
 */
interface AffectsSaleProfitability
{
    public function saleId(): int;
}
