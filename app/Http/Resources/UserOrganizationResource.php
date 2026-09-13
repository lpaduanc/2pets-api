<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vínculo ativo de um `User` com uma `Organization`, usado em `UserResource` (`GET /user`) —
 * é como o frontend descobre o(s) `organization_id` do usuário logado para chamar
 * `/api/organizations/{organization}/members`. Requer `organization` eager-carregada
 * (ver `User::activeOrganizationMemberships()`), nunca dispara query por linha.
 *
 * @mixin \App\Models\OrganizationMember
 */
class UserOrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->organization->id,
            'name' => $this->organization->business_name,
            'type' => $this->organization->organization_type->value,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
        ];
    }
}
