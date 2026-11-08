<?php

namespace App\Services\Fiscal\Transmission;

use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\DataTransferObjects\Fiscal\FiscalTransmissionStatus;
use App\Enums\FiscalEnvironment;
use App\Models\Organization;
use App\Services\Fiscal\Certificate\FiscalCertificateVault;
use App\Services\Fiscal\Certificate\FiscalNfceCscService;
use App\Support\Fiscal\IbgeMunicipalities;

/**
 * Decide se o dono transmite à SEFAZ ou segue em emissão simulada, e diz o que falta — spec
 * docs/gap-petflow/specs/fase4-transmissao-propria-nfe-nfce.md §6.
 *
 * Regra do driver (pedido do produto): SEFAZ quando a configuração permite E há A1 válido (e,
 * para NFC-e, o CSC do ambiente). Dado do emitente incompleto NÃO volta para o simulado — com
 * certificado, simular em silêncio seria pior; ele aparece em `missing` e a SEFAZ (ou a validação
 * local do XML) recusa o documento com o motivo. Vet volante (sem organização) fica no simulado:
 * NF-e/NFC-e própria exige CNPJ.
 */
final class FiscalTransmissionReadiness
{
    public const MISSING_ORGANIZATION = 'organization';

    public const MISSING_SEFAZ_DISABLED = 'sefaz_disabled';

    public const MISSING_CERTIFICATE = 'certificate';

    public const MISSING_CERTIFICATE_EXPIRED = 'certificate_expired';

    public const MISSING_NFCE_CSC = 'nfce_csc';

    /** Campo do emitente => código em `missing`. */
    private const EMITTER_FIELDS = [
        'cnpj' => 'cnpj',
        'state_registration' => 'state_registration',
        'tax_regime' => 'tax_regime',
        'address' => 'address',
        'number' => 'address',
        'neighborhood' => 'address',
        'zip_code' => 'address',
        'city' => 'address',
        'state' => 'address',
    ];

    public function __construct(
        private readonly FiscalCertificateVault $vault,
        private readonly FiscalNfceCscService $cscs,
        private readonly IbgeMunicipalities $municipalities,
    ) {}

    public function check(FiscalOwner $owner): FiscalTransmissionStatus
    {
        if ($owner->organizationId === null) {
            return new FiscalTransmissionStatus(self::documents(false, false), [self::MISSING_ORGANIZATION], null);
        }

        $organization = Organization::findOrFail($owner->organizationId);
        $environment = $organization->fiscal_environment ?? FiscalEnvironment::HOMOLOGATION;
        $enabled = (bool) config('fiscal.sefaz.enabled');
        $certificateProblem = $this->certificateProblem($organization->id);
        $hasCsc = $this->cscs->has($organization->id, $environment);
        $usesSefaz = $enabled && $certificateProblem === null;

        return new FiscalTransmissionStatus(self::documents($usesSefaz, $usesSefaz && $hasCsc), array_values(array_unique(array_filter([
            $enabled ? null : self::MISSING_SEFAZ_DISABLED,
            $certificateProblem,
            $hasCsc ? null : self::MISSING_NFCE_CSC,
            ...$this->emitterProblems($organization),
        ]))), $environment->value);
    }

    private function certificateProblem(int $organizationId): ?string
    {
        $certificate = $this->vault->metadata($organizationId);

        return match (true) {
            $certificate === null => self::MISSING_CERTIFICATE,
            $certificate->isExpired() => self::MISSING_CERTIFICATE_EXPIRED,
            default => null,
        };
    }

    /** @return list<string> */
    private function emitterProblems(Organization $organization): array
    {
        $problems = array_values(array_map(
            fn (string $field): string => self::EMITTER_FIELDS[$field],
            array_filter(array_keys(self::EMITTER_FIELDS), fn (string $field): bool => self::isBlank($organization->getAttribute($field))),
        ));

        if ($this->emitterCityCode($organization) === null) {
            $problems[] = 'city_ibge_code';
        }

        return $problems;
    }

    /** cMun do emitente: o código informado na configuração fiscal vence; senão, a tabela do IBGE. */
    public function emitterCityCode(Organization $organization): ?string
    {
        return $organization->city_ibge_code ?: $this->municipalities->code($organization->city, $organization->state);
    }

    private static function isBlank(mixed $value): bool
    {
        return blank($value instanceof \BackedEnum ? $value->value : $value);
    }

    /** @return array{nfe: string, nfce: string} */
    private static function documents(bool $nfe, bool $nfce): array
    {
        return [
            'nfe' => $nfe ? FiscalTransmissionStatus::MODE_SEFAZ : FiscalTransmissionStatus::MODE_SIMULATED,
            'nfce' => $nfce ? FiscalTransmissionStatus::MODE_SEFAZ : FiscalTransmissionStatus::MODE_SIMULATED,
        ];
    }
}
