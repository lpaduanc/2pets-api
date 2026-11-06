<?php

namespace App\Services\Commercial\Package;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\SoldPackage;
use App\Models\SoldPackageConsumption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Indicadores derivados do pacote vendido para a tela (PLANO 2.7). Exige `consumptions.appointment`
 * e `appointments` carregados.
 *  - Retorno médio: média dos dias corridos entre baixas consecutivas (datas distintas), 1 casa;
 *    `null` com menos de duas datas.
 *  - Próximo agendamento: o mais cedo, a partir de agora, marcado para o pacote
 *    (`appointments.sold_package_id`) e ainda em aberto.
 *  - Último agendamento: o mais recente já passado/concluído entre os marcados para o pacote e
 *    os usados nas baixas. Cancelado e não compareceu ficam de fora dos dois.
 */
final class SoldPackageInsights
{
    private const CLOSED_WITHOUT_SERVICE = ['cancelled', 'no_show'];

    public function averageReturnDays(SoldPackage $package): ?float
    {
        $dates = $package->consumptions->map(fn (SoldPackageConsumption $c): string => $c->consumed_at->toDateString())->unique()->sort()->values();

        if ($dates->count() < 2) {
            return null;
        }

        $gaps = $dates->sliding(2)->map(fn (Collection $pair): int => (int) CarbonImmutable::parse($pair->first())->diffInDays(CarbonImmutable::parse($pair->last())));

        return round($gaps->avg(), 1);
    }

    /** @return array{id: int, starts_at: string, status: string}|null */
    public function nextAppointment(SoldPackage $package): ?array
    {
        $next = $package->appointments
            ->reject(fn (Appointment $a): bool => in_array($a->status, [...self::CLOSED_WITHOUT_SERVICE, AppointmentStatus::COMPLETED->value], true))
            ->filter(fn (Appointment $a): bool => $this->startsAt($a)->gte(now()))
            ->sortBy(fn (Appointment $a): string => $this->startsAt($a)->toIso8601String())
            ->first();

        return $next === null ? null : $this->present($next);
    }

    /** @return array{id: int, starts_at: string, status: string}|null */
    public function lastAppointment(SoldPackage $package): ?array
    {
        $last = $package->appointments->concat($package->consumptions->pluck('appointment')->filter())
            ->unique('id')
            ->reject(fn (Appointment $a): bool => in_array($a->status, self::CLOSED_WITHOUT_SERVICE, true))
            ->filter(fn (Appointment $a): bool => $a->status === AppointmentStatus::COMPLETED->value || $this->startsAt($a)->lt(now()))
            ->sortByDesc(fn (Appointment $a): string => $this->startsAt($a)->toIso8601String())
            ->first();

        return $last === null ? null : $this->present($last);
    }

    private function startsAt(Appointment $appointment): CarbonImmutable
    {
        $time = $appointment->appointment_time?->format('H:i') ?? '00:00';

        return CarbonImmutable::parse($appointment->appointment_date->toDateString().' '.$time);
    }

    /** @return array{id: int, starts_at: string, status: string} */
    private function present(Appointment $appointment): array
    {
        return ['id' => $appointment->id, 'starts_at' => $this->startsAt($appointment)->toIso8601String(), 'status' => $appointment->status];
    }
}
