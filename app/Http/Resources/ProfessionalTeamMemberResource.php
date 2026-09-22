<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Card de "escolha o profissional da equipe" — Fase 2 do fluxo de agendamento
 * (`Api\Public\TeamController`). `matched_service_price`/`has_schedule` são atributos
 * virtuais anexados pelo `OrganizationTeamService`, não colunas do model.
 */
class ProfessionalTeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $professional = $this->professional;
        $isVeterinarian = $this->isVeterinarian();
        $activeServices = $professional?->services;

        return [
            'id' => $this->id,
            'name' => $professional?->business_name ?: $this->name,
            'avatar_url' => $this->avatar_url ?? null,
            'specialties' => $professional?->specialties ?? [],
            'crmv' => $isVeterinarian ? $professional?->crmv : null,
            'crmv_state' => $isVeterinarian ? $professional?->crmv_state : null,
            // Preço do serviço filtrado (`?service_id=`), quando houver; senão o menor
            // preço ativo do profissional — mesma semântica de `starting_price` do card
            // de busca (`ProfessionalSearchCardResource`).
            'price' => $this->when(
                $this->matched_service_price !== null,
                fn (): float => (float) $this->matched_service_price
            ),
            'starting_price' => $this->startingPrice($activeServices),
            'has_schedule' => (bool) ($this->has_schedule ?? false),
        ];
    }

    private function startingPrice(?object $activeServices): ?float
    {
        $minPrice = $activeServices?->min('price');

        return $minPrice !== null ? (float) $minPrice : null;
    }
}
