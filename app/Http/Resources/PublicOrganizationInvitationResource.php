<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * View pública do convite (sem auth) — expõe só o necessário pra tela de aceite decidir o que
 * mostrar antes do login/cadastro. Nunca inclui `token` (já está na URL) nem dado da
 * organização além do nome.
 *
 * @mixin \App\Models\OrganizationInvitation
 */
class PublicOrganizationInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'organization_name' => $this->organization->business_name,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'email' => $this->email,
            'expired' => $this->isExpired(),
        ];
    }
}
