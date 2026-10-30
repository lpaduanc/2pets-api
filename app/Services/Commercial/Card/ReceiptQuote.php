<?php

namespace App\Services\Commercial\Card;

use App\Support\Money\Money;

/**
 * Taxa, líquido e agenda de um recebimento — o mesmo objeto serve à simulação e ao registro.
 * `charged` = valor-base + `surcharge` (repasse da taxa ao cliente); sem repasse, são iguais.
 */
final readonly class ReceiptQuote
{
    /** @param  list<PlannedReceivable>  $plan */
    public function __construct(
        public Money $fee,
        public Money $net,
        public ?FeeTerms $terms,
        public array $plan,
        public Money $surcharge,
        public Money $charged,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'pass_to_customer' => $this->terms?->passToCustomer ?? false,
            'surcharge_amount' => $this->surcharge->toString(),
            'charged_amount' => $this->charged->toString(),
            'fee_percent' => $this->terms?->feePercent,
            'fee_fixed' => $this->terms?->feeFixed,
            'fee_amount' => $this->fee->toString(),
            'net_amount' => $this->net->toString(),
            'installments' => array_map(fn (PlannedReceivable $p): array => $p->toArray(), $this->plan),
        ];
    }
}
