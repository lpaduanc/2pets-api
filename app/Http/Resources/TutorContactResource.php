<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projeção MÍNIMA de um tutor dentro de um vínculo de acesso ao pet.
 *
 * Só identifica quem concedeu (ou concederá) o acesso. Nada de e-mail, telefone, CPF, data de
 * nascimento ou endereço: no fluxo em que este payload aparece — inclusive solicitações ainda
 * `pending` — o veterinário não tem consentimento para dado pessoal do tutor.
 *
 * ⚠️ Contato do tutor (telefone/e-mail) para o vet que JÁ tem acesso concedido é outra
 * discussão, e hoje vive em `PetPatientResource`. Não replique aqui.
 *
 * Relação esperada (ver `PetVetAccess::PARTICIPANT_RELATIONS`): `media`.
 */
class TutorContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\User $this */
        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar_url' => $this->avatarUrl(),
        ];
    }

    private function avatarUrl(): ?string
    {
        if (! $this->resource->relationLoaded('media')) {
            return null;
        }

        return $this->getFirstMediaUrl('avatar') ?: null;
    }
}
