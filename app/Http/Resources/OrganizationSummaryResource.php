<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resumo mínimo de `Organization` devolvido por `POST /register/complete-professional` — só o
 * suficiente para o app saber que a organização existe e chamar `/api/organizations/{id}` para
 * o resto. Contrato combinado com o frontend: `id`, `business_name`, `organization_type`.
 *
 * @mixin \App\Models\Organization
 */
class OrganizationSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'organization_type' => $this->organization_type->value,
        ];
    }
}
