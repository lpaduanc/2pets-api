<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Professional
 */
class ProfessionalLegalProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'title' => $this->title,
            'crmv' => $this->crmv,
            'crmv_state' => $this->crmv_state,
            'mapa_registration' => $this->mapa_registration,
            // Nunca o path cru — armazenamento privado (spec 15, regra "storage privado, TTL curto").
            'has_signature_image' => $this->signature_image_path !== null,
        ];
    }
}
