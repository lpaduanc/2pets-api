<?php

namespace App\Services\Professional;

use App\Enums\PetSpecies;
use App\Exceptions\Pet\DuplicatePetCandidatesException;
use App\Models\Pet;
use App\Models\User;

/**
 * Resolve qual `Pet` este agendamento usa — contrato
 * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §4. Nome sozinho não
 * desambigua entre espécies diferentes, mas a regra de negócio (`docs/atendimento-veterinario/
 * 06-agendamento-pet-novo.md` §3.1) compara só o NOME — espécie entra como informação exibida
 * ao profissional para ele decidir, nunca como filtro que reduziria o alerta.
 */
final class NewPatientPetResolver
{
    /**
     * @param  array{pet_name: string, pet_species: string, existing_pet_id: ?int, create_new_pet: bool}  $data
     *
     * @throws DuplicatePetCandidatesException quando o nome bate com um pet vivo do tutor e o
     *                                         profissional ainda não disse qual dos dois caminhos seguir.
     */
    public function resolve(User $tutor, array $data): Pet
    {
        if ($data['existing_pet_id'] !== null) {
            return $tutor->pets()->findOrFail($data['existing_pet_id']);
        }

        if (! $data['create_new_pet']) {
            $this->guardAgainstNameCollision($tutor, $data['pet_name']);
        }

        return $tutor->pets()->create([
            'name' => $data['pet_name'],
            'species' => PetSpecies::from($data['pet_species'])->value,
        ]);
    }

    private function guardAgainstNameCollision(User $tutor, string $petName): void
    {
        $matches = $tutor->pets()->whereRaw('lower(name) = ?', [mb_strtolower($petName)])->get();

        if ($matches->isNotEmpty()) {
            throw new DuplicatePetCandidatesException($matches);
        }
    }
}
