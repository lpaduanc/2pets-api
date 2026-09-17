<?php

namespace App\Exceptions\Pet;

use App\Http\Resources\Professional\PetDuplicateCandidateResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Contrato `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §4 — "o servidor
 * não decide". O tutor já tem um pet com o mesmo nome; quem escolhe se é o mesmo animal é o
 * profissional, reenviando com `existing_pet_id` ou `create_new_pet: true`.
 *
 * Formato de resposta FIXADO em conjunto com o frontend — `candidates` na RAIZ do corpo, não
 * dentro de `data`. Não mude sem atualizar o contrato e avisar no relatório.
 */
final class DuplicatePetCandidatesException extends RuntimeException
{
    /** @param  Collection<int, \App\Models\Pet>  $candidates */
    public function __construct(private readonly Collection $candidates)
    {
        parent::__construct('Este tutor já tem um pet com este nome.');
    }

    /** Resultado de negócio esperado, não incidente: não polui o log com stack trace. */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'candidates' => PetDuplicateCandidateResource::collection($this->candidates),
        ], 409);
    }
}
