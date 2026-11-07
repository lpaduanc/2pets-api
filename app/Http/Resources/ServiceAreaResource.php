<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceAreaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'active' => $this->active,
            'organization_id' => $this->organization_id,
            'professional_id' => $this->professional_id,
            // PLANO 3.7 — zona do leva e traz.
            'delivery_enabled' => (bool) $this->delivery_enabled,
            'rule_type' => $this->delivery_rule_type,
            'ceps' => $this->delivery_ceps ?? [],
            'max_km' => $this->delivery_max_km === null ? null : (string) $this->delivery_max_km,
            'fee' => $this->delivery_fee === null ? null : (string) $this->delivery_fee,
        ];
    }
}
