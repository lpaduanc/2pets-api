<?php

namespace App\Services\Commercial\Goals;

use App\Models\Holiday;
use App\Models\Organization;
use Carbon\CarbonImmutable;

/**
 * Dias úteis do mês para a meta de venda (PLANO 2.5). Dia útil = dia da semana em que a
 * organização trabalha (`organizations.working_days`; vazio = segunda a sexta, a mesma regra de
 * calendário do `DueDateService`) e que não é feriado do catálogo (`Holiday`, global ou da
 * organização, inclusive o anual).
 *
 * Decorridos = dias úteis do mês ANTES de hoje: hoje só conta como decorrido ao fim do dia
 * (até lá ainda é um dia para vender, então entra nos restantes). Mês passado: todos decorridos;
 * mês futuro: nenhum.
 *
 * `working_days` aceita os dois formatos que existem no projeto: número (0/7 = domingo,
 * 1 = segunda … 6 = sábado) ou nome em inglês (`monday`).
 */
final class BusinessDayCalendar
{
    private const NAMES = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /** @return array{total: int, elapsed: int, remaining: int} */
    public function forMonth(?Organization $organization, string $month, ?CarbonImmutable $today = null): array
    {
        $today = ($today ?? \App\Support\BusinessClock::today())->startOfDay();
        $first = CarbonImmutable::parse($month.'-01')->startOfDay();
        $workingDays = $this->workingDays($organization);
        $holidays = Holiday::query()->visibleTo($organization?->id)->where('active', true)->get(['date', 'recurring_annually']);
        $total = $elapsed = 0;

        for ($day = $first; $day->month === $first->month; $day = $day->addDay()) {
            if (! in_array($day->dayOfWeek, $workingDays, true) || $holidays->contains(fn (Holiday $h): bool => $h->coversDate($day))) {
                continue;
            }

            $total++;
            $elapsed += $day->lt($today) ? 1 : 0;
        }

        return ['total' => $total, 'elapsed' => $elapsed, 'remaining' => $total - $elapsed];
    }

    /** @return list<int> dias no formato do Carbon (0 = domingo) */
    private function workingDays(?Organization $organization): array
    {
        $configured = collect($organization?->working_days ?? [])
            ->map(fn ($day): ?int => match (true) {
                is_numeric($day) => ((int) $day) % 7,
                is_string($day) => ($index = array_search(strtolower($day), self::NAMES, true)) === false ? null : $index,
                default => null,
            })
            ->filter(fn (?int $day): bool => $day !== null)
            ->unique()->values()->all();

        return $configured === [] ? [1, 2, 3, 4, 5] : $configured;
    }
}
