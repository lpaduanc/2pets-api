<?php

namespace App\Services\Fiscal\Transmission;

use App\DataTransferObjects\Fiscal\FiscalTransmissionStatus;
use App\Enums\FiscalEnvironment;
use App\Enums\NfseLayout;
use App\Models\Organization;
use App\Services\Fiscal\Nfse\NfseTaxationRules;
use App\Services\Fiscal\Nfse\Transport\NfseServerTrust;
use App\Support\Fiscal\AlphanumericCnpjSupport;

/**
 * Bloco `transmission.nfse` — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfse-nacional.md
 * §3. Sistema Nacional quando `fiscal.nfse.enabled` E A1 válido; senão simulado. Como na fase A,
 * dado incompleto (emitente, regime, CA bundle) NÃO volta para o simulado: aparece em `missing` e
 * a DPS é recusada (ou não sai) com o motivo.
 */
final class NfseTransmissionReadiness
{
    public const MISSING_DISABLED = 'nfse_disabled';

    /** A inscrição estadual é da NF-e/NFC-e; a DPS não a usa. */
    private const NOT_APPLICABLE = ['state_registration'];

    public function __construct(
        private readonly NfseTaxationRules $rules,
        private readonly NfseServerTrust $trust,
    ) {}

    /**
     * @param  list<string>  $emitterProblems  do `FiscalTransmissionReadiness`
     * @return array{mode: string, ready: bool, missing: list<string>}
     */
    public function check(Organization $organization, ?string $certificateProblem, array $emitterProblems): array
    {
        $enabled = (bool) config('fiscal.nfse.enabled');
        $missing = array_values(array_unique(array_filter([
            $enabled ? null : self::MISSING_DISABLED,
            $certificateProblem,
            ...array_diff($emitterProblems, self::NOT_APPLICABLE),
            ...$this->rules->missing($organization),
            $this->trust->problem(),
            AlphanumericCnpjSupport::problem($organization->cnpj, AlphanumericCnpjSupport::nfseLayoutAccepts(self::layout($organization))),
        ])));

        return self::block($enabled && $certificateProblem === null, $missing);
    }

    /** Leiaute da DPS no ambiente da organização (o CNPJ alfanumérico só existe no da reforma). */
    private static function layout(Organization $organization): NfseLayout
    {
        return NfseLayout::forEnvironment($organization->fiscal_environment ?? FiscalEnvironment::HOMOLOGATION);
    }

    /** @return array{mode: string, ready: bool, missing: list<string>} */
    public static function withoutOrganization(): array
    {
        return self::block(false, [FiscalTransmissionReadiness::MISSING_ORGANIZATION]);
    }

    /**
     * @param  list<string>  $missing
     * @return array{mode: string, ready: bool, missing: list<string>}
     */
    private static function block(bool $national, array $missing): array
    {
        return [
            'mode' => $national ? FiscalTransmissionStatus::MODE_NATIONAL : FiscalTransmissionStatus::MODE_SIMULATED,
            'ready' => $missing === [],
            'missing' => $missing,
        ];
    }
}
