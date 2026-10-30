<?php

namespace App\Services\Commercial\Card;

/**
 * Taxa e prazo que valem para um recebimento de cartão — resolvidos por `FeeTierResolver` e
 * congelados no recebimento (`fee_percent_applied`, `fee_fixed_applied`).
 *
 * `passToCustomer`: a faixa repassa a taxa ao cliente (acréscimo calculado por
 * `FeePassThroughCalculator`). A taxa base da forma, sem faixa, nunca repassa.
 */
final readonly class FeeTerms
{
    public function __construct(
        public ?int $tierId,
        public string $feePercent,
        public string $feeFixed,
        public int $firstSettlementDays,
        public int $intervalDays,
        public bool $passToCustomer = false,
    ) {}
}
