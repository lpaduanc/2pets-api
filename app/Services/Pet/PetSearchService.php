<?php

namespace App\Services\Pet;

use App\DataTransferObjects\Cpf;
use App\DataTransferObjects\PetSearchFilters;
use App\DataTransferObjects\PetVetAccessSnapshot;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Busca que o veterinário usa para localizar um pet antes de pedir acesso.
 *
 * Cada resultado já vem com o estado do vínculo daquele vet com aquele pet
 * (`vet_access_snapshot`), para o vet enxergar ANTES de submeter quais pets do tutor ainda
 * faltam e em que nível. Um tutor tem vários pets e cada um tem seu próprio vínculo —
 * descobrir o conflito só no 409 depois de escolher era o defeito.
 *
 * Regras de performance (a base cresce: 300k pets / 200k users hoje no benchmark):
 *   - Só igualdade sobre coluna indexada — nunca `LIKE '%…%'` nem função sobre a coluna,
 *     que trocariam index scan por seq scan na tabela inteira.
 *   - CPF resolvido por subconsulta em `users_cpf_unique` (uma ida ao banco, não duas).
 *   - Estado do acesso resolvido em UMA query auxiliar sobre os pets já paginados
 *     (`idx_pet_vet_accesses_pet_id`), nunca por pet — a listagem é O(1) em queries.
 */
final class PetSearchService
{
    /** Projeção mínima: nenhum dado clínico sai daqui — o vet ainda não tem acesso concedido. */
    private const RESULT_COLUMNS = [
        'id',
        'user_id',
        'breed_id',
        'public_id',
        'name',
        'species',
        'breed',
        'gender',
        'birth_date',
        'image_url',
        'microchip_number',
    ];

    public function search(PetSearchFilters $filters, User $vet): LengthAwarePaginator
    {
        $pets = Pet::query()
            ->select(self::RESULT_COLUMNS)
            ->with(['user:id,name', 'breedRelation:id,name'])
            ->when($filters->tutorCpf, fn (Builder $query, Cpf $cpf) => $this->whereTutorCpf($query, $cpf))
            ->when($filters->microchipNumber, fn (Builder $query, string $chip) => $query->where('microchip_number', $chip))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters->perPage);

        $this->attachVetAccessState($pets, $vet);

        return $pets;
    }

    /**
     * Subconsulta em vez de dois SELECTs: o planner resolve `users_cpf_unique` primeiro e usa
     * o id resultante como `Index Cond` de `idx_pets_user_id`, sem roundtrip extra da aplicação.
     */
    private function whereTutorCpf(Builder $query, Cpf $cpf): Builder
    {
        return $query->whereIn('user_id', function ($subQuery) use ($cpf): void {
            $subQuery->select('id')
                ->from('users')
                ->where('cpf', $cpf->digits)
                ->whereNull('deleted_at');
        });
    }

    private function attachVetAccessState(LengthAwarePaginator $pets, User $vet): void
    {
        $snapshots = $this->snapshotsByPet($pets->getCollection()->modelKeys(), $vet->id);

        foreach ($pets as $pet) {
            $pet->setAttribute('vet_access_snapshot', $snapshots[$pet->id] ?? PetVetAccessSnapshot::empty());
        }
    }

    /**
     * Um par (pet, vet) acumula linhas ao longo do tempo — recusadas, revogadas, substituídas
     * por upgrade — e pode ter DUAS vivas ao mesmo tempo: a concessão `accepted` e um pedido
     * de upgrade `pending`. Trazemos todas as linhas dos pets já paginados numa query só
     * (`idx_pet_vet_accesses_pet_id`) e o snapshot decide o que cada uma significa.
     *
     * @param  list<int>  $petIds
     * @return array<int, PetVetAccessSnapshot>
     */
    private function snapshotsByPet(array $petIds, int $vetId): array
    {
        if ($petIds === []) {
            return [];
        }

        return PetVetAccess::query()
            ->select(['id', 'pet_id', 'status', 'access_level', 'requested_access_level', 'granted_at', 'requested_at', 'responded_at'])
            ->whereIn('pet_id', $petIds)
            ->where('veterinarian_id', $vetId)
            ->orderBy('pet_id')
            ->orderByDesc('id')
            ->get()
            ->groupBy('pet_id')
            ->map(fn ($accesses): PetVetAccessSnapshot => PetVetAccessSnapshot::fromAccesses($accesses->values()->all()))
            ->all();
    }
}
