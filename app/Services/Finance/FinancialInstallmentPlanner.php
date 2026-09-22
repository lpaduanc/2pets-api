<?php

namespace App\Services\Finance;

use App\DataTransferObjects\Finance\PlannedFinancialInstallment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Divide o valor de um lançamento em N parcelas — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * O centavo que sobra da divisão vai na ÚLTIMA parcela (100,00 em 3× = 33,33 + 33,33 + 33,34):
 * a soma bate com o total sempre. Vencimento em fim de semana ou feriado cadastrado rola para
 * o próximo dia útil (`DueDateService`, doc 03).
 */
final class FinancialInstallmentPlanner
{
    public function __construct(private readonly ?DueDateService $dueDates = null) {}

    /**
     * @return Collection<int, PlannedFinancialInstallment>
     */
    public function plan(float $totalAmount, int $count, CarbonImmutable $firstDueDate, int $intervalDays = 30, ?int $organizationId = null): Collection
    {
        $count = max(1, $count);
        $seriesId = $count === 1 ? null : (string) Str::uuid();
        $totalCents = (int) round($totalAmount * 100);
        $baseCents = intdiv($totalCents, $count);

        return collect(range(1, $count))->map(
            fn (int $number): PlannedFinancialInstallment => new PlannedFinancialInstallment(
                $seriesId,
                $number,
                $count,
                $this->dueDateFor($firstDueDate, $number, $intervalDays, $organizationId),
                $this->amountFor($number, $count, $totalCents, $baseCents) / 100,
            )
        );
    }

    private function dueDateFor(CarbonImmutable $firstDueDate, int $number, int $intervalDays, ?int $organizationId): CarbonImmutable
    {
        $due = $intervalDays === 30
            ? $firstDueDate->addMonthsNoOverflow($number - 1)
            : $firstDueDate->addDays($intervalDays * ($number - 1));

        while ($due->isWeekend() || ($this->dueDates?->isHoliday($due, $organizationId) ?? false)) {
            $due = $due->addDay();
        }

        return $due;
    }

    private function amountFor(int $number, int $count, int $totalCents, int $baseCents): int
    {
        return $number === $count ? $totalCents - ($baseCents * ($count - 1)) : $baseCents;
    }
}
