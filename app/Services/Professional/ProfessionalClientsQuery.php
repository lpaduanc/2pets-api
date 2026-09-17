<?php

namespace App\Services\Professional;

use App\Models\PetVetAccess;
use App\Models\ProfessionalClient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query object: "quem é cliente deste profissional". Extraído de
 * `ProfessionalClientController::clientsQuery` (docs/atendimento-veterinario/
 * 08-consulta-autorizada-por-agendamento.md §A) para o walk-in e o `store()` de agendamento
 * do profissional reusarem a MESMA definição — antes cada call site que precisava responder
 * "este usuário é cliente deste profissional?" corria o risco de reimplementar as quatro fontes
 * de forma incompleta (foi exatamente o bug original do `destroy()`, que nem olhava
 * `PetVetAccess`).
 *
 * Um usuário que se encaixa em mais de uma fonte aparece uma única vez — é um único
 * `WHERE ... OR ...`, não uma união de listas.
 */
final class ProfessionalClientsQuery
{
    /**
     * Fontes: agendamento com este profissional, fatura deste profissional, tutor de um pet
     * com `PetVetAccess` ativo para este profissional, ou vínculo manual
     * (`professional_clients`).
     */
    public function query(int $professionalId): Builder
    {
        return User::where('id', '!=', $professionalId)
            ->where(function (Builder $query) use ($professionalId) {
                $query->whereHas('appointmentsAsClient', function ($q) use ($professionalId) {
                    $q->where('professional_id', $professionalId);
                })
                    ->orWhereHas('invoicesAsClient', function ($q) use ($professionalId) {
                        $q->where('professional_id', $professionalId);
                    })
                    ->orWhereIn('id', $this->tutorIdsWithActiveGrantTo($professionalId))
                    ->orWhereIn('id', $this->manuallyLinkedClientIds($professionalId));
            });
    }

    public function isClientOf(int $professionalId, int $userId): bool
    {
        return $this->query($professionalId)->whereKey($userId)->exists();
    }

    /**
     * Subquery-style helper returning tutor IDs whose pets have an active grant for this professional.
     */
    private function tutorIdsWithActiveGrantTo(int $professionalId)
    {
        return PetVetAccess::query()
            ->where('veterinarian_id', $professionalId)
            ->active()
            ->join('pets', 'pets.id', '=', 'pet_vet_accesses.pet_id')
            ->distinct()
            ->pluck('pets.user_id');
    }

    /** IDs de cliente com vínculo manual vivo (`professional_clients`, ver `ProfessionalClientController::store`). */
    private function manuallyLinkedClientIds(int $professionalId)
    {
        return ProfessionalClient::query()
            ->where('professional_id', $professionalId)
            ->pluck('client_id');
    }
}
