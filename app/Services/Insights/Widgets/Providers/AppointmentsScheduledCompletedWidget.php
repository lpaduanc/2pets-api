<?php

namespace App\Services\Insights\Widgets\Providers;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Services\Insights\Widgets\DashboardWidgetContext;

/**
 * Agendados × realizados, 6 meses (o atual e os 5 anteriores): agendados = agendamentos NÃO
 * cancelados com data no mês (não compareceu conta como agendado); realizados = `completed` com
 * data no mês. Taxa = realizados / agendados × 100 (`null` sem agendado). Domínio agenda:
 * `agenda.view.any`.
 */
final class AppointmentsScheduledCompletedWidget extends BaseWidgetProvider
{
    private const MONTHS = 6;

    public function key(): string
    {
        return 'appointments_scheduled_completed';
    }

    public function label(): string
    {
        return 'Agendados × realizados';
    }

    public function description(): string
    {
        return 'Agendamentos e atendimentos concluídos nos últimos 6 meses.';
    }

    public function domain(): string
    {
        return 'agenda';
    }

    public function permission(): string
    {
        return 'agenda.view.any';
    }

    public function data(DashboardWidgetContext $context): array
    {
        $first = $context->today->startOfMonth()->subMonths(self::MONTHS - 1);
        $month = "to_char(appointments.appointment_date, 'YYYY-MM')";
        $rows = $this->scoped(Appointment::query(), $context)
            ->whereBetween('appointments.appointment_date', [$first->toDateString(), $context->today->endOfMonth()->toDateString()])
            ->where('appointments.status', '!=', AppointmentStatus::CANCELLED->value)
            ->groupByRaw($month)
            ->selectRaw("{$month} AS month, COUNT(*) AS scheduled, SUM(CASE WHEN appointments.status = ? THEN 1 ELSE 0 END) AS completed", [AppointmentStatus::COMPLETED->value])
            ->toBase()->get()->keyBy('month');

        $months = [];

        for ($cursor = $first; $cursor->lte($context->today); $cursor = $cursor->addMonthNoOverflow()) {
            $key = $cursor->format('Y-m');
            $scheduled = (int) ($rows[$key]->scheduled ?? 0);
            $completed = (int) ($rows[$key]->completed ?? 0);
            $months[] = ['month' => $key, 'scheduled' => $scheduled, 'completed' => $completed, 'completion_percent' => $scheduled === 0 ? null : (string) \Brick\Math\BigDecimal::of($completed)->multipliedBy(100)->dividedBy($scheduled, 2, \Brick\Math\RoundingMode::HalfUp)];
        }

        return ['months' => $months];
    }
}
