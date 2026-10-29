<?php

namespace App\Services\Purchase;

use App\Services\Finance\DueDateService;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Parcelas de uma compra: valores e vencimentos.
 *
 * Centavo que sobra da divisão vai na ÚLTIMA parcela (100,00 em 3× = 33,33 + 33,33 + 33,34) —
 * a soma bate com o total da nota sempre. Vencimento em fim de semana rola para o próximo dia
 * útil sempre; feriado cadastrado (`DueDateService`, doc 03) também empurra quando o
 * colaborador está disponível — opcional (`null`) para o teste unitário puro
 * (`tests/Unit/AverageCostTest.php`) continuar instanciando sem container/banco.
 */
final class InstallmentPlanner
{
    public function __construct(private readonly ?DueDateService $dueDates = null) {}

    /**
     * @return list<array{number: int, due_date: string, amount: float}>
     */
    public function plan(float $total, int $count, CarbonImmutable $firstDueDate, int $intervalDays = 30, ?int $organizationId = null): array
    {
        $amounts = Money::of($total)->splitInInstallments(max(1, $count), remainderOnLast: true);
        $plan = [];

        foreach ($amounts as $i => $amount) {
            $due = $intervalDays === 30
                ? $firstDueDate->addMonthsNoOverflow($i)
                : $firstDueDate->addDays($intervalDays * $i);

            $plan[] = [
                'number' => $i + 1,
                'due_date' => $this->rollToBusinessDay($due, $organizationId)->toDateString(),
                'amount' => $amount->toFloat(),
            ];
        }

        return $plan;
    }

    private function rollToBusinessDay(CarbonImmutable $date, ?int $organizationId): CarbonImmutable
    {
        while ($date->isWeekend() || ($this->dueDates?->isHoliday($date, $organizationId) ?? false)) {
            $date = $date->addDay();
        }

        return $date;
    }
}
