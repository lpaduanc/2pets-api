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
        return $this->queryForAny([$professionalId]);
    }

    /**
     * Mesma definição de `query()`, para um CONJUNTO de profissionais — "cliente de alguém da
     * equipe". Existe para o balcão da clínica (docs/gap-simplesvet/01-caixa-pdv.md): a
     * recepcionista vende para o tutor que é cliente do veterinário, não dela. As quatro
     * fontes são as mesmas; só o `=` vira `IN`.
     *
     * @param  list<int>  $professionalIds
     */
    public function queryForAny(array $professionalIds): Builder
    {
        return User::whereNotIn('id', $professionalIds)
            ->where(function (Builder $query) use ($professionalIds) {
                $query->whereHas('appointmentsAsClient', function ($q) use ($professionalIds) {
                    $q->whereIn('professional_id', $professionalIds);
                })
                    ->orWhereHas('invoicesAsClient', function ($q) use ($professionalIds) {
                        $q->whereIn('professional_id', $professionalIds);
                    })
                    ->orWhereIn('id', $this->tutorIdsWithActiveGrantTo($professionalIds))
                    ->orWhereIn('id', $this->manuallyLinkedClientIds($professionalIds));
            });
    }

    public function isClientOf(int $professionalId, int $userId): bool
    {
        return $this->query($professionalId)->whereKey($userId)->exists();
    }

    /**
     * Subquery-style helper returning tutor IDs whose pets have an active grant for this professional.
     */
    /** @param  list<int>  $professionalIds */
    private function tutorIdsWithActiveGrantTo(array $professionalIds)
    {
        return PetVetAccess::query()
            ->whereIn('veterinarian_id', $professionalIds)
            ->active()
            ->join('pets', 'pets.id', '=', 'pet_vet_accesses.pet_id')
            ->distinct()
            ->pluck('pets.user_id');
    }

    /** IDs de cliente com vínculo manual vivo (`professional_clients`, ver `ProfessionalClientController::store`). */
    /** @param  list<int>  $professionalIds */
    private function manuallyLinkedClientIds(array $professionalIds)
    {
        return ProfessionalClient::query()
            ->whereIn('professional_id', $professionalIds)
            ->pluck('client_id');
    }
}
