<?php

namespace App\Services\Hospitalization;

use App\Enums\HospitalizationStatus;
use App\Exceptions\Hospitalization\HospitalizationBoxOccupiedException;
use App\Models\Hospitalization;

/**
 * Regra de negócio 1 da spec docs/gap-simplesvet/specs/12-internacao-mapa-execucao-spec.md:
 * um box só aceita UMA internação `active` por vez. Validação de aplicação — o índice único
 * parcial criado na mesma migration (`hospitalizations_active_box_unique`) é a rede de
 * segurança contra corrida concorrente; esta classe é quem devolve o 422 amigável no caminho
 * comum, sem corrida.
 */
final class HospitalizationBoxOccupancyGuard
{
    /**
     * @throws HospitalizationBoxOccupiedException
     */
    public function assertAvailable(?int $boxId, ?int $ignoringHospitalizationId = null): void
    {
        if ($boxId === null) {
            return;
        }

        if ($this->hasActiveOccupant($boxId, $ignoringHospitalizationId)) {
            throw new HospitalizationBoxOccupiedException;
        }
    }

    private function hasActiveOccupant(int $boxId, ?int $ignoringHospitalizationId): bool
    {
        return Hospitalization::query()
            ->where('box_id', $boxId)
            ->where('status', HospitalizationStatus::ACTIVE->value)
            ->when($ignoringHospitalizationId !== null, fn ($query) => $query->whereKeyNot($ignoringHospitalizationId))
            ->exists();
    }
}
