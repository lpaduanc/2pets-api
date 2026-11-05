<?php

namespace App\Services\Finance\Anticipation;

use App\Enums\AnticipationMode;
use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * A conta da antecipação de recebíveis (docs/gap-petflow/PLANO.md, 2.1), pura — desconto simples
 * sobre o LÍQUIDO de cada parcela:
 *
 *   taxa ao mês:  d_i = dias corridos da data da antecipação até a data da parcela;
 *                 taxa_i = round(líquido_i × taxa/100 × d_i/30)
 *   taxa fixa:    taxa_total = round(Σ líquido × taxa/100); taxa_i = rateio pelo maior resto
 *                 com pesos = líquido_i (Σ taxa_i = taxa_total, centavo a centavo)
 *   recebido = Σ (líquido_i − taxa_i);  custo efetivo% = Σ taxa_i / Σ líquido_i (2 casas)
 *
 * Arredondamento HALF_UP uma vez por parcela (seção 3). Casos de referência em
 * `tests/Fixtures/golden/anticipation.json`.
 */
final class AnticipationCalculator
{
    private const DAYS_PER_MONTH = 30;

    /**
     * @param  list<array{receivable_id: int, net_amount: string, expected_date: string}>  $items
     * @return array{anticipated_at: string, mode: string, rate: string, items: list<array<string, mixed>>, gross_total: string, fee_total: string, net_total: string, effective_cost_percent: string|null}
     */
    public function calculate(array $items, string $anticipatedAt, AnticipationMode $mode, string $rate): array
    {
        $date = CarbonImmutable::parse($anticipatedAt)->startOfDay();
        $fees = $mode === AnticipationMode::MONTHLY_RATE ? $this->monthlyFees($items, $date, $rate) : $this->fixedFees($items, $rate);
        $lines = array_map(fn (array $item, Money $fee): array => [
            'receivable_id' => $item['receivable_id'],
            'expected_date' => CarbonImmutable::parse($item['expected_date'])->toDateString(),
            'days' => self::days($date, $item['expected_date']),
            'net_amount' => Money::of($item['net_amount'])->toString(),
            'fee_amount' => $fee->toString(),
            'received_amount' => Money::of($item['net_amount'])->minus($fee)->toString(),
        ], $items, array_values($fees));

        $gross = Money::sum(array_column($lines, 'net_amount'));
        $fee = Money::sum(array_column($lines, 'fee_amount'));

        return [
            'anticipated_at' => $date->toDateString(),
            'mode' => $mode->value,
            'rate' => (string) Decimal::round($rate, 4),
            'items' => $lines,
            'gross_total' => $gross->toString(),
            'fee_total' => $fee->toString(),
            'net_total' => $gross->minus($fee)->toString(),
            'effective_cost_percent' => $gross->isPositive() ? (string) $fee->toBigDecimal()->multipliedBy(100)->dividedBy($gross->toBigDecimal(), 2, RoundingMode::HalfUp) : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<Money>
     */
    private function monthlyFees(array $items, CarbonImmutable $date, string $rate): array
    {
        return array_map(fn (array $item): Money => Money::of(
            Decimal::of($item['net_amount'])->multipliedBy(Decimal::of($rate))->multipliedBy(self::days($date, $item['expected_date']))
                ->dividedBy(100 * self::DAYS_PER_MONTH, 10, RoundingMode::HalfUp)
        ), $items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<Money>
     */
    private function fixedFees(array $items, string $rate): array
    {
        $nets = array_map(fn (array $item): Money => Money::of($item['net_amount']), $items);
        $total = Money::sum($nets)->percentage(Decimal::of($rate));

        return array_values($total->isZero() ? array_map(fn (): Money => Money::zero(), $nets) : $total->allocate($nets));
    }

    private static function days(CarbonImmutable $date, string $expectedDate): int
    {
        return max(0, (int) $date->diffInDays(CarbonImmutable::parse($expectedDate)->startOfDay(), false));
    }
}
