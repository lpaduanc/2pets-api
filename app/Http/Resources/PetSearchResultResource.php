<?php

namespace App\Http\Resources;

use App\DataTransferObjects\PetVetAccessSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resultado da busca que o vet faz ANTES de ter acesso ao pet.
 *
 * Expõe de propósito só o mínimo para o vet reconhecer o animal e confirmar que é o tutor
 * certo. Nada de dado clínico e nada de contato do tutor (e-mail, telefone, CPF, endereço):
 * o consentimento ainda não foi dado. Ampliar este payload é decisão de LGPD, não de UI.
 *
 * `vet_access` descreve o vínculo do VET AUTENTICADO com aquele pet — é o que permite o app
 * marcar cada pet do tutor e desabilitar os que já estão cobertos, em vez de deixar o vet
 * descobrir o conflito só no 409 depois de submeter.
 *
 * Como quem decide o nível é o tutor, "já tenho vínculo" deixou de ser resposta suficiente:
 * `granted_access_level` diz até onde o acesso atual vai e `pending_request_level` diz se já
 * existe upgrade em análise. `can_request` combina os dois — é `true` também quando existe
 * acesso concedido abaixo de `full` sem pedido em voo.
 *
 * `status` reflete o acesso EFETIVO: com `read` concedido e `full` pendente ele continua
 * `accepted`, e o pedido em voo aparece em `pending_request_level`.
 *
 * Espera `vet_access_snapshot` pré-computado por `PetSearchService`.
 */
class PetSearchResultResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\Pet $this */
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'species' => $this->species,
            'breed' => $this->breedRelation?->name ?? $this->breed,
            'gender' => $this->gender,
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'image_url' => $this->image_url,
            'has_microchip' => $this->microchip_number !== null,
            'tutor' => $this->whenLoaded('user', fn (): array => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'vet_access' => $this->vetAccessPayload(),
        ];
    }

    /**
     * @return array{status: string, can_request: bool, access_id: int|null, since: string|null,
     *               granted_access_level: string|null, pending_request_level: string|null}
     */
    private function vetAccessPayload(): array
    {
        $snapshot = $this->resource->vet_access_snapshot ?? PetVetAccessSnapshot::empty();

        return [
            'status' => $snapshot->state()->value,
            'can_request' => $snapshot->canRequest(),
            'access_id' => $snapshot->accessId(),
            'since' => $snapshot->since(),
            'granted_access_level' => $snapshot->grantedLevel()?->value,
            'pending_request_level' => $snapshot->pendingRequestLevel()?->value,
        ];
    }
}
