<?php

namespace App\Services\Commercial\Pricing;

use App\Support\Money\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;

/**
 * O que sai do preço antes de sobrar para o custo (docs/gap-petflow/PLANO.md, 1.4): taxa de
 * cartão de referência, imposto efetivo e comissão — todos em %. `null` entra na conta como 0:
 * taxa/imposto `null` = não cadastrado (a simulação avisa); comissão `null` = use a do item (e,
 * sem item, 0).
 */
final readonly class PricingContext
{
    public function __construct(
        public ?string $cardFeePercent = null,
        public ?string $taxPercent = null,
        public ?string $commissionPercent = null,
    ) {}

    /** Σ das deduções em %, com a comissão do item quando o contexto não fixou outra. */
    public function deductionsPercent(BigNumber|string|null $itemCommissionPercent = null): BigDecimal
    {
        return Decimal::of($this->cardFeePercent)
            ->plus(Decimal::of($this->taxPercent))
            ->plus(Decimal::of($this->commissionPercent ?? $itemCommissionPercent));
    }
}
