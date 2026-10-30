<?php

namespace App\Services\Commercial\Card;

use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/** Uma parcela planejada: o que a adquirente deposita, quando e com quanto de taxa. */
final readonly class PlannedReceivable
{
    public function __construct(
        public int $number,
        public Money $gross,
        public Money $fee,
        public Money $net,
        public CarbonImmutable $expectedDate,
    ) {}

    /** @return array{number: int, gross_amount: string, fee_amount: string, net_amount: string, expected_date: string} */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'gross_amount' => $this->gross->toString(),
            'fee_amount' => $this->fee->toString(),
            'net_amount' => $this->net->toString(),
            'expected_date' => $this->expectedDate->toDateString(),
        ];
    }
}
