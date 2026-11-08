<?php

namespace App\Services\Fiscal\Transmission;

use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\Http\Resources\Fiscal\FiscalCertificateResource;
use App\Models\Organization;
use App\Services\Fiscal\Certificate\FiscalCertificateVault;
use App\Services\Fiscal\Certificate\FiscalNfceCscService;

/**
 * Parte de transmissão própria do `GET fiscal-settings` — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §6: modo/prontidão, metadados do A1 (nunca o arquivo, a
 * senha ou a referência interna), CSC por ambiente (sem o token) e o cMun do emitente.
 */
final class FiscalTransmissionSettings
{
    public function __construct(
        private readonly FiscalTransmissionReadiness $readiness,
        private readonly FiscalCertificateVault $vault,
        private readonly FiscalNfceCscService $cscs,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(Organization $organization): array
    {
        $certificate = $this->vault->metadata($organization->id);

        return [
            'transmission' => $this->readiness->check(FiscalOwner::forOrganization($organization->id))->toArray(),
            'certificate' => $certificate === null ? null : (new FiscalCertificateResource($certificate))->resolve(),
            'nfce_csc' => $this->cscs->summary($organization),
            'city_ibge_code' => $organization->city_ibge_code,
            'resolved_city_ibge_code' => $this->readiness->emitterCityCode($organization),
        ];
    }
}
