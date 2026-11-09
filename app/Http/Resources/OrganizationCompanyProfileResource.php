<?php

namespace App\Http\Resources;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dados cadastrais da empresa (`professional/organization`). O que depende de outra tabela ou da
 * tabela do IBGE chega pronto pelo construtor — o Resource só formata, não consulta.
 *
 * @mixin Organization
 */
class OrganizationCompanyProfileResource extends JsonResource
{
    public function __construct(
        Organization $organization,
        private readonly ?string $resolvedCityIbgeCode,
        private readonly bool $cnpjLocked,
    ) {
        parent::__construct($organization);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_type' => $this->organization_type?->value,
            'business_name' => $this->business_name,
            'cnpj' => $this->cnpj,
            'address' => $this->address,
            'number' => $this->number,
            'complement' => $this->complement,
            'neighborhood' => $this->neighborhood,
            'city' => $this->city,
            'state' => $this->state,
            'zip_code' => $this->zip_code,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            // cMun do emitente que a NF-e vai usar (informado na config fiscal ou achado pelo nome
            // da cidade + UF); `null` = a tela avisa que a cidade não bateu com o IBGE.
            'resolved_city_ibge_code' => $this->resolvedCityIbgeCode,
            // Já há nota emitida com este CNPJ: a tela bloqueia o campo em vez de esperar o 422.
            'cnpj_locked' => $this->cnpjLocked,
            'has_certificate' => $this->certificate_ref !== null,
        ];
    }
}
