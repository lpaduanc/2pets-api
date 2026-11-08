<?php

namespace App\DataTransferObjects\Fiscal;

use App\Enums\TaxProfileSource;
use App\Enums\TaxRegime;
use App\Models\FiscalDocumentItem;
use App\Models\TaxProfile;

/**
 * Regra tributária JÁ resolvida de um item: perfil + regime (CSOSN ou CST, nunca os dois) + CFOP.
 * Entrada do `FiscalTaxCalculator` (puro). Alíquotas e percentuais como string de 4 casas.
 */
final readonly class FiscalTaxRule
{
    public function __construct(
        public ?string $cfop,
        public string $origin,
        public ?string $csosn,
        public ?string $cst,
        public string $icmsRate = '0',
        public string $icmsBaseReductionPercent = '0',
        public string $snCreditRate = '0',
        public string $fcpRate = '0',
        public ?string $pisCst = null,
        public string $pisRate = '0',
        public ?string $cofinsCst = null,
        public string $cofinsRate = '0',
        public ?string $benefitCode = null,
        public ?int $taxProfileId = null,
        public ?TaxProfileSource $profileSource = null,
    ) {}

    /** Perfil do cadastro no regime do emitente: CSOSN no Simples/MEI, CST no Presumido/Real. */
    public static function fromProfile(TaxProfile $profile, ?TaxRegime $regime, ?string $cfop, TaxProfileSource $source): self
    {
        $usesCsosn = $regime?->usesCsosn() === true;

        return new self(
            cfop: $cfop,
            origin: $profile->origin->value,
            csosn: $usesCsosn ? $profile->icms_csosn : null,
            cst: $usesCsosn ? null : $profile->icms_cst,
            icmsRate: (string) $profile->icms_rate,
            icmsBaseReductionPercent: (string) $profile->icms_base_reduction_percent,
            snCreditRate: (string) $profile->icms_sn_credit_rate,
            fcpRate: (string) $profile->fcp_rate,
            pisCst: $profile->pis_cst,
            pisRate: (string) $profile->pis_rate,
            cofinsCst: $profile->cofins_cst,
            cofinsRate: (string) $profile->cofins_rate,
            benefitCode: $profile->benefit_code,
            taxProfileId: $profile->id,
            profileSource: $source,
        );
    }

    /** A mesma regra congelada no item original — a devolução espelha, nunca relê o cadastro. */
    public static function fromSnapshot(FiscalDocumentItem $item, ?string $cfop): self
    {
        return new self(
            cfop: $cfop,
            origin: (string) $item->origin,
            csosn: $item->csosn,
            cst: $item->cst,
            icmsRate: (string) $item->icms_rate,
            icmsBaseReductionPercent: (string) $item->icms_base_reduction_percent,
            snCreditRate: (string) $item->icms_sn_credit_rate,
            fcpRate: (string) $item->fcp_rate,
            pisCst: $item->pis_cst,
            pisRate: (string) $item->pis_rate,
            cofinsCst: $item->cofins_cst,
            cofinsRate: (string) $item->cofins_rate,
            benefitCode: $item->benefit_code,
            taxProfileId: $item->tax_profile_id,
            profileSource: $item->profile_source,
        );
    }

    /** @param  array<string, string|null>  $values  chaves em snake_case, como no golden */
    public static function fromArray(array $values): self
    {
        return new self(
            cfop: $values['cfop'] ?? null,
            origin: $values['origin'] ?? '0',
            csosn: $values['csosn'] ?? null,
            cst: $values['cst'] ?? null,
            icmsRate: $values['icms_rate'] ?? '0',
            icmsBaseReductionPercent: $values['icms_base_reduction_percent'] ?? '0',
            snCreditRate: $values['icms_sn_credit_rate'] ?? '0',
            fcpRate: $values['fcp_rate'] ?? '0',
            pisCst: $values['pis_cst'] ?? null,
            pisRate: $values['pis_rate'] ?? '0',
            cofinsCst: $values['cofins_cst'] ?? null,
            cofinsRate: $values['cofins_rate'] ?? '0',
        );
    }

    /** Campos do snapshot que vêm da regra (não do cálculo). @return array<string, mixed> */
    public function toSnapshot(): array
    {
        return [
            'cfop' => $this->cfop,
            'origin' => $this->origin,
            'csosn' => $this->csosn,
            'cst' => $this->cst,
            'icms_base_reduction_percent' => $this->icmsBaseReductionPercent,
            'pis_cst' => $this->pisCst,
            'cofins_cst' => $this->cofinsCst,
            'benefit_code' => $this->benefitCode,
            'tax_profile_id' => $this->taxProfileId,
            'profile_source' => $this->profileSource?->value,
        ];
    }
}
