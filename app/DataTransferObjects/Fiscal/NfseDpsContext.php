<?php

namespace App\DataTransferObjects\Fiscal;

use App\Enums\FiscalEnvironment;
use App\Enums\NfseLayout;
use Carbon\CarbonImmutable;

/**
 * O que a DPS precisa além do payload neutro — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §4/§5: leiaute do ambiente, série/número nossos,
 * datas no fuso do emitente, cMun do emitente e do tomador, regime (`NfseTaxationRules`).
 */
final readonly class NfseDpsContext
{
    private const FEDERAL_REGISTRATION_CNPJ = '2';

    /**
     * @param  array{opSimpNac: string, regApTribSN: string|null, regEspTrib: string}  $regime
     * @param  array<string, mixed>  $approximateTaxes  choice de `totTrib`
     */
    public function __construct(
        public NfseLayout $layout,
        public FiscalEnvironment $environment,
        public string $series,
        public int $number,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $competence,
        public string $cityCode,
        public ?string $recipientCityCode,
        public array $regime,
        public array $approximateTaxes,
    ) {}

    /** Série com 5 dígitos (formato do Id e aceito pelos dois XSDs). */
    public function paddedSeries(): string
    {
        return str_pad($this->series, 5, '0', STR_PAD_LEFT);
    }

    /**
     * `infDPS/@Id`: "DPS" + cLocEmi (7) + tipo de inscrição (1 = CPF, 2 = CNPJ) + inscrição (14)
     * + série (5) + número (15) — Anexo I, linha 101.
     */
    public function dpsId(string $cnpj): string
    {
        return 'DPS'.$this->cityCode.self::FEDERAL_REGISTRATION_CNPJ.str_pad($cnpj, 14, '0', STR_PAD_LEFT)
            .$this->paddedSeries().str_pad((string) $this->number, 15, '0', STR_PAD_LEFT);
    }
}
