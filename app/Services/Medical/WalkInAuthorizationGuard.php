<?php

namespace App\Services\Medical;

use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use App\Services\Professional\ProfessionalClientsQuery;

/**
 * Portão de autorização compartilhado por qualquer fluxo que crie o PRÓPRIO agendamento e,
 * por isso, não pode se autoautorizar por status de agendamento (contrato
 * docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §A) — hoje
 * `ConsultationService::startWalkIn()` e
 * `App\Services\Hospitalization\HospitalizationService::admit()` (contrato
 * docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §6): o tutor do pet
 * já precisa ser cliente deste profissional (`ProfessionalClientsQuery`) OU o profissional
 * já ter `PetVetAccess` ativo sobre o pet. Extraído para os dois call sites nunca divergirem
 * sobre o que conta como "autorizado".
 */
final class WalkInAuthorizationGuard
{
    public function __construct(private readonly ProfessionalClientsQuery $professionalClientsQuery) {}

    public function assertAuthorized(Pet $pet, User $professional): void
    {
        if ($this->professionalClientsQuery->isClientOf($professional->id, $pet->user_id)) {
            return;
        }

        if ($this->hasActivePetVetAccess($professional->id, $pet->id)) {
            return;
        }

        abort(403, 'Este tutor ainda não é seu cliente. Use "paciente novo" ou o agendamento normal.');
    }

    private function hasActivePetVetAccess(int $professionalId, int $petId): bool
    {
        return PetVetAccess::query()
            ->where('veterinarian_id', $professionalId)
            ->where('pet_id', $petId)
            ->active()
            ->exists();
    }
}
