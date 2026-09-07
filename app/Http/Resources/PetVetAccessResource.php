<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Um vínculo veterinário↔pet em qualquer estado do handshake de consentimento.
 *
 * As duas partes aparecem em projeção MÍNIMA (`VetContactResource`/`TutorContactResource`),
 * nunca com `UserResource` inteiro. Este payload é servido inclusive para solicitações ainda
 * `pending`, quando nenhum dos dois lados consentiu com nada: entregar e-mail, telefone,
 * data de nascimento e endereço com latitude/longitude da contraparte era exposição de dado
 * pessoal sem base legal.
 *
 * Regra: campo novo aqui sobre uma pessoa entra por decisão explícita de LGPD, não por
 * conveniência de tela.
 */
class PetVetAccessResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pet_id' => $this->pet_id,
            'pet' => new PetResource($this->whenLoaded('pet')),
            'veterinarian_id' => $this->veterinarian_id,
            'veterinarian' => new VetContactResource($this->whenLoaded('veterinarian')),
            'granted_by' => $this->granted_by,
            'grantor' => new TutorContactResource($this->whenLoaded('grantor')),
            // `access_level` é o nível CONCEDIDO pelo tutor — nulo enquanto `pending`.
            // `requested_access_level` é o que o vet indicou precisar e não vincula ninguém;
            // é o que o tutor lê para decidir.
            'access_level' => $this->access_level,
            'requested_access_level' => $this->requested_access_level,
            // `status` e as datas do handshake faltavam no payload, embora o app filtre por
            // elas (`activeAccessesForPet`/`historyAccessesForPet` no vet-access-store) e a
            // resposta 409 precise delas para dizer se o vínculo está pendente ou concedido.
            'status' => $this->status,
            'requested_at' => $this->requested_at?->toISOString(),
            'responded_at' => $this->responded_at?->toISOString(),
            'granted_at' => $this->granted_at?->toISOString(),
            'revoked_at' => $this->revoked_at?->toISOString(),
            'superseded_at' => $this->superseded_at?->toISOString(),
            'superseded_by_id' => $this->superseded_by_id,
            'rejection_reason' => $this->rejection_reason,
            'revocation_reason' => $this->revocation_reason,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
