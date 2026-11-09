<?php

namespace App\Services\Fiscal\Nfse;

use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\DataTransferObjects\Fiscal\NfseDpsContext;
use App\Enums\FiscalEnvironment;
use App\Enums\NfseLayout;
use App\Exceptions\Fiscal\NfeXmlInvalid;
use App\Models\FiscalDocument;
use App\Models\Organization;
use App\Services\Fiscal\Transmission\FiscalTransmissionReadiness;
use App\Support\Fiscal\IbgeMunicipalities;
use Carbon\CarbonImmutable;
use NFePHP\Common\TimeZoneByUF;

/**
 * Monta o `NfseDpsContext` de um documento — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §5.1:
 *
 * - `dhEmi` = agora no fuso da UF, recuado `fiscal.nfse.clock_skew_seconds` (E0008 recusa emissão
 *   depois do processamento na Sefin);
 * - `dCompet` = dia em que o documento nasceu (o fato gerador), nunca depois do dhEmi (E0015);
 * - leiaute pelo ambiente GRAVADO no documento, não pela configuração atual.
 */
final class NfseDpsContextFactory
{
    private const FALLBACK_TIMEZONE = 'America/Sao_Paulo';

    public function __construct(
        private readonly NfseTaxationRules $rules,
        private readonly FiscalTransmissionReadiness $readiness,
        private readonly IbgeMunicipalities $municipalities,
    ) {}

    /** @throws NfeXmlInvalid */
    public function make(FiscalDocument $document, FiscalInvoicePayload $payload): NfseDpsContext
    {
        $organization = Organization::findOrFail($document->organization_id);
        $environment = $document->environment ?? FiscalEnvironment::HOMOLOGATION;
        $issuedAt = CarbonImmutable::now(self::timezone($organization->state))->subSeconds((int) config('fiscal.nfse.clock_skew_seconds'));
        $createdAt = CarbonImmutable::parse($document->created_at ?? $issuedAt)->setTimezone($issuedAt->getTimezone());

        return new NfseDpsContext(
            layout: NfseLayout::forEnvironment($environment),
            environment: $environment,
            series: (string) $document->dps_series,
            number: $document->dps_number ?? throw NfeXmlInvalid::because(['DPS sem número reservado.']),
            issuedAt: $issuedAt,
            competence: $createdAt->min($issuedAt),
            cityCode: $this->readiness->emitterCityCode($organization) ?? throw NfeXmlInvalid::because(['Município do emitente sem código IBGE: confira cidade/UF ou informe o código na configuração fiscal.']),
            recipientCityCode: $this->municipalities->code($payload->recipient?->address['city'] ?? null, $payload->recipient?->state()),
            regime: $this->rules->regime($organization),
            approximateTaxes: $this->rules->approximateTaxes($organization),
        );
    }

    private static function timezone(?string $state): string
    {
        try {
            return TimeZoneByUF::get((string) $state);
        } catch (\Throwable) {
            return self::FALLBACK_TIMEZONE;
        }
    }
}
