<?php

namespace App\Services\Appointment;

use App\Contracts\HasDepositSettings;
use App\Models\Organization;
use App\Models\Professional;
use App\Models\Service;

/**
 * "Este serviço cobra sinal, e quanto?" (Fase 6 do fluxo de agendamento). Resolução, na
 * ordem que o dono do produto pediu: serviço (se tiver override) → estabelecimento →
 * desligado. `0%` é tratado como equivalente a desligado (percentual zero não gera cobrança
 * nenhuma) — é o jeito de um serviço "desligar" o sinal do estabelecimento sem precisar de
 * um segundo campo.
 */
final class DepositConfigResolver
{
    /**
     * `null` quando não há sinal aplicável — chamador não precisa checar `enabled`
     * separadamente, só tratar `null` como "sem sinal".
     */
    public function resolvePercentageFor(Service $service): ?float
    {
        $percentage = $this->resolveRaw($service);

        return ($percentage === null || $percentage <= 0.0) ? null : $percentage;
    }

    private function resolveRaw(Service $service): ?float
    {
        if ($service->deposit_enabled !== null) {
            return $service->deposit_enabled ? $this->percentageOf($service) : null;
        }

        $establishment = $this->establishmentFor($service);

        return $establishment?->depositEnabled() === true ? $establishment->depositPercentage() : null;
    }

    private function percentageOf(Service $service): ?float
    {
        return $service->deposit_percentage === null ? null : (float) $service->deposit_percentage;
    }

    private function establishmentFor(Service $service): ?HasDepositSettings
    {
        if ($service->organization_id !== null) {
            return Organization::find($service->organization_id);
        }

        return Professional::where('user_id', $service->professional_id)->first();
    }
}
