<?php

namespace App\Services\Fiscal\Nfse;

use App\Enums\TaxRegime;
use App\Models\Organization;
use App\Support\Money\Decimal;

/**
 * Regras de regime da DPS — spec docs/gap-petflow/specs/fase4-transmissao-propria-nfse-nacional.md
 * §5.1/§5.4, com os códigos de rejeição do Anexo I v1.01 que cada escolha evita:
 *
 * - `opSimpNac`: Lucro Presumido/Real 1, MEI 2, Simples (ME/EPP) 3 (E0160 confere no cadastro);
 * - `regApTribSN`: só para 3 e sempre 1 = tudo pelo SN (E0162/E0166);
 * - `regEspTrib`: 0, salvo não optante com código oficial em `special_tax_regime` (E0174/E0175);
 * - `totTrib` (Lei 12.741): MEI ⇒ `indTotTrib 0`; ME/EPP ⇒ `pTotTribSN` (nunca `indTotTrib`,
 *   E0712); não optante ⇒ `pTotTrib` (nunca `indTotTrib`/`pTotTribSN`, E0713).
 */
final class NfseTaxationRules
{
    public const MISSING_SIMPLES_RATE = 'simples_effective_rate';

    public const MISSING_ISS_RATE = 'iss_rate';

    private const NOT_OPTANT = '1';

    private const MEI = '2';

    private const SIMPLES = '3';

    private const APURATION_ALL_BY_SIMPLES = '1';

    private const NO_SPECIAL_REGIME = '0';

    /** Regimes especiais municipais aceitos pelo leiaute (TSRegEspTrib, sem o 0). */
    private const SPECIAL_REGIMES = ['1', '2', '3', '4', '5', '6', '9'];

    private const ZERO_PERCENT = '0.00';

    /** @return array{opSimpNac: string, regApTribSN: string|null, regEspTrib: string} */
    public function regime(Organization $organization): array
    {
        $option = self::simplesOption($organization->tax_regime);

        return [
            'opSimpNac' => $option,
            'regApTribSN' => $option === self::SIMPLES ? self::APURATION_ALL_BY_SIMPLES : null,
            'regEspTrib' => $this->specialRegime($organization, $option),
        ];
    }

    /**
     * Grupo `totTrib`: um dos três formatos do choice do leiaute.
     *
     * @return array{indTotTrib: string}|array{pTotTribSN: string}|array{pTotTrib: array{pTotTribFed: string, pTotTribEst: string, pTotTribMun: string}}
     */
    public function approximateTaxes(Organization $organization): array
    {
        return match (self::simplesOption($organization->tax_regime)) {
            self::MEI => ['indTotTrib' => '0'],
            self::SIMPLES => ['pTotTribSN' => self::percent($organization->effective_sales_tax_rate)],
            // Federal/estadual aproximados ainda sem fonte (IBPT/contador): spec §12, item 4.
            default => ['pTotTrib' => ['pTotTribFed' => self::ZERO_PERCENT, 'pTotTribEst' => self::ZERO_PERCENT, 'pTotTribMun' => self::percent($organization->iss_rate)]],
        };
    }

    /**
     * Dado do regime que falta para montar o `totTrib`.
     *
     * @return list<string>
     */
    public function missing(Organization $organization): array
    {
        return match (self::simplesOption($organization->tax_regime)) {
            self::SIMPLES => blank($organization->effective_sales_tax_rate) ? [self::MISSING_SIMPLES_RATE] : [],
            self::NOT_OPTANT => blank($organization->iss_rate) && $organization->tax_regime !== null ? [self::MISSING_ISS_RATE] : [],
            default => [],
        };
    }

    private static function simplesOption(?TaxRegime $regime): string
    {
        return match ($regime) {
            TaxRegime::MEI => self::MEI,
            TaxRegime::SIMPLES_NACIONAL => self::SIMPLES,
            default => self::NOT_OPTANT,
        };
    }

    private function specialRegime(Organization $organization, string $option): string
    {
        $code = trim((string) $organization->special_tax_regime);

        return $option === self::NOT_OPTANT && in_array($code, self::SPECIAL_REGIMES, true) ? $code : self::NO_SPECIAL_REGIME;
    }

    /** Percentual com 2 casas (TSDec2V2), arredondado uma vez, sem float. */
    private static function percent(mixed $value): string
    {
        return (string) Decimal::round($value ?? '0', 2);
    }
}
