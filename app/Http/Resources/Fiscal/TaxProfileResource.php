<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxProfile;
use App\Models\TaxProfileCfop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Perfil tributário (spec fase4-fiscal §6). Alíquotas como string de 4 casas, como o resto do
 * módulo de precificação.
 *
 * @mixin TaxProfile
 */
class TaxProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_default' => $this->is_default,
            'active' => $this->active,
            'origin' => $this->origin->value,
            'origin_label' => $this->origin->label(),
            'icms_csosn' => $this->icms_csosn,
            'icms_cst' => $this->icms_cst,
            'icms_rate' => (string) $this->icms_rate,
            'icms_base_reduction_percent' => (string) $this->icms_base_reduction_percent,
            'icms_sn_credit_rate' => (string) $this->icms_sn_credit_rate,
            'fcp_rate' => (string) $this->fcp_rate,
            'pis_cst' => $this->pis_cst,
            'pis_rate' => (string) $this->pis_rate,
            'cofins_cst' => $this->cofins_cst,
            'cofins_rate' => (string) $this->cofins_rate,
            'benefit_code' => $this->benefit_code,
            'notes' => $this->notes,
            'cfops' => $this->whenLoaded('cfops', fn (): array => $this->cfops->map(fn (TaxProfileCfop $cfop): array => [
                'operation' => $cfop->operation->value,
                'operation_label' => $cfop->operation->label(),
                'scope' => $cfop->scope->value,
                'cfop' => $cfop->cfop,
            ])->values()->all()),
            'products_count' => $this->whenCounted('products'),
            'product_groups_count' => $this->whenCounted('productGroups'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
