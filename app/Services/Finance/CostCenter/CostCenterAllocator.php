<?php

namespace App\Services\Finance\CostCenter;

use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\BigInteger;
use Brick\Math\Exception\RoundingNecessaryException;
use Illuminate\Validation\ValidationException;

/**
 * Rateio de um lançamento entre centros de custo (docs/gap-petflow/PLANO.md, 2.4), puro — sem
 * banco:
 *
 *   Σ percent = 100,0000 EXATO, comparado em inteiros de 4 casas (1.000.000), nunca em float;
 *   amount_i  = allocate(net_amount, pesos = percent_i)  — maior resto, Σ amounts = net_amount.
 *
 * Percentual com mais de 4 casas, zero/negativo ou centro repetido também é recusado (422,
 * erro em `allocations`). Casos de referência em `tests/Fixtures/golden/cost-center.json`.
 */
final class CostCenterAllocator
{
    private const HUNDRED_PERCENT_UNITS = 1_000_000;

    /**
     * @param  list<array{cost_center_id: int, percent: string|int|float}>  $allocations
     * @return list<array{cost_center_id: int, percent: string, amount: string}>
     */
    public function allocate(string $netAmount, array $allocations, string $field = 'allocations'): array
    {
        $this->assertValid($allocations, $field);

        $percents = array_map(fn (array $line): string => (string) Decimal::of($line['percent'])->toScale(4), $allocations);
        $amounts = Money::of($netAmount)->allocate($percents);

        return array_map(fn (array $line, int $i): array => [
            'cost_center_id' => (int) $line['cost_center_id'],
            'percent' => $percents[$i],
            'amount' => $amounts[$i]->toString(),
        ], $allocations, array_keys($allocations));
    }

    /** @param  list<array{cost_center_id: int, percent: string|int|float}>  $allocations */
    public function assertValid(array $allocations, string $field = 'allocations'): void
    {
        if ($allocations === []) {
            throw ValidationException::withMessages([$field => 'Informe ao menos um centro de custo no rateio.']);
        }

        $ids = array_map(fn (array $line): int => (int) $line['cost_center_id'], $allocations);

        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages([$field => 'O mesmo centro de custo aparece duas vezes no rateio.']);
        }

        $total = BigInteger::zero();

        foreach ($allocations as $line) {
            $units = $this->units($line['percent'], $field);
            $total = $total->plus($units);
        }

        if (! $total->isEqualTo(self::HUNDRED_PERCENT_UNITS)) {
            throw ValidationException::withMessages([$field => sprintf('A soma do rateio tem de ser 100,0000%% (está em %s%%).', number_format($total->toInt() / 10000, 4, ',', '.'))]);
        }
    }

    /** Percentual em inteiros de 4 casas (33,3333% = 333.333). */
    private function units(string|int|float $percent, string $field): BigInteger
    {
        try {
            $units = Decimal::of($percent)->toScale(4)->getUnscaledValue();
        } catch (RoundingNecessaryException) {
            throw ValidationException::withMessages([$field => 'Percentual de rateio aceita no máximo 4 casas decimais.']);
        }

        if (! $units->isPositive()) {
            throw ValidationException::withMessages([$field => 'Percentual de rateio tem de ser maior que zero.']);
        }

        return $units;
    }
}
