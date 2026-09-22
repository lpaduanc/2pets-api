<?php

namespace App\Services\Finance;

use App\Models\Holiday;
use Carbon\CarbonImmutable;

/**
 * "Próximo dia útil" para vencimento de parcela — contrato
 * docs/gap-simplesvet/03-contas-a-pagar-receber-spec.md.
 *
 * Sábado/domingo é dia não útil mesmo sem estar cadastrado em `holidays` (regra de calendário,
 * não de cadastro). Feriado cadastrado empurra a data também. Usado por
 * `Finance\FinancialInstallmentPlanner` (spec 02) e `Purchase\InstallmentPlanner` (doc 06).
 *
 * `App\Models\Holiday` é o catálogo único de feriado do projeto (item 23) — consolidado com o
 * que antes era `company_holidays` (item 03); ver migration
 * `2026_10_30_700000_consolidate_holidays_with_company_holidays`.
 */
final class DueDateService
{
    public function nextBusinessDay(CarbonImmutable $date, ?int $organizationId = null): CarbonImmutable
    {
        while ($date->isWeekend() || $this->isHoliday($date, $organizationId)) {
            $date = $date->addDay();
        }

        return $date;
    }

    /**
     * Filtra em PHP, não em SQL: `EXTRACT`/`strftime` divergem entre Postgres (produção) e
     * sqlite (suíte de teste) — o mesmo cuidado documentado para migration com SQL de driver
     * específico. O volume de feriados por escopo é pequeno; trazer tudo e comparar é barato.
     */
    public function isHoliday(CarbonImmutable $date, ?int $organizationId = null): bool
    {
        return Holiday::query()
            ->visibleTo($organizationId)
            ->get(['date', 'recurring_annually'])
            ->contains(fn (Holiday $holiday): bool => $holiday->coversDate($date));
    }
}
