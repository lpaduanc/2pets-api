<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Projeção MÍNIMA de um veterinário para o tutor decidir sobre um pedido de acesso.
 *
 * Minimização por padrão (LGPD art. 6º, III): este payload aparece inclusive em solicitações
 * `pending` — momento em que o tutor ainda não consentiu nada e, mesmo assim, o vet já é
 * identificado para ele. Antes daqui, o campo `veterinarian` usava `UserResource` inteiro e
 * entregava e-mail, telefone, data de nascimento, CNPJ, ocupação e o **endereço completo com
 * latitude/longitude** do profissional.
 *
 * O que fica é só o necessário para o tutor responder "confio neste profissional?":
 * quem é (nome/foto) e qual a credencial (CRMV + UF + selo de verificação).
 *
 * ⚠️ Não acrescente e-mail nem telefone aqui. Se alguma tela precisar de contato, isso é
 * decisão de produto/LGPD à parte — e o canal existente é o chat da plataforma.
 *
 * Relações esperadas (ver `PetVetAccess::PARTICIPANT_RELATIONS`): `professional` e `media`.
 * Sem elas o payload não quebra, mas perde CRMV e avatar.
 */
class VetContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\User $this */
        $professional = $this->whenLoaded('professional', fn () => $this->professional);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'avatar_url' => $this->avatarUrl(),
            // Nome fantasia do negócio, não dado pessoal: já é público em `/public/search`
            // para visitante anônimo. Ajuda o tutor a reconhecer de onde conhece o profissional.
            'business_name' => $professional?->business_name,
            'crmv' => $professional?->crmv,
            'crmv_state' => $professional?->crmv_state,
            'is_crmv_verified' => (bool) ($professional?->is_crmv_verified ?? false),
        ];
    }

    /**
     * `getFirstMediaUrl()` numa relação não carregada dispara uma query por linha — N+1
     * garantido numa lista. Sem `media` carregada, devolve null.
     */
    private function avatarUrl(): ?string
    {
        if (! $this->resource->relationLoaded('media')) {
            return null;
        }

        return $this->getFirstMediaUrl('avatar') ?: null;
    }
}
