<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\OrganizationMember
 */
class OrganizationMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'is_active' => $this->is_active,
            'hire_date' => $this->hire_date?->toDateString(),
            'has_crmv' => filled($this->user->professional?->crmv),

            // Item 21 do backlog gap-simplesvet — seletor de área de atendimento por membro
            // (`TeamPage.vue`). `whenLoaded` evita N+1: quem chama este Resource em coleção
            // precisa eager-loadar `serviceAreas` (ver `OrganizationMemberService::listMembers()`).
            'service_areas' => ServiceAreaResource::collection($this->whenLoaded('serviceAreas')),

            // Item 22 — exceção pontual não-clínica por membro
            // (`OrganizationMember::hasPermission()`). Nunca inclui ato clínico: a blindagem
            // do model já impede a concessão, isto só reflete o que está gravado.
            'permissions' => $this->permissions ?? [],
        ];
    }
}
