<?php

namespace App\Services\Fiscal\Nfse\Danfse;

use App\Services\Fiscal\Nfse\NfseDocumentReader;
use App\Services\Fiscal\Nfse\NfseText;
use Carbon\CarbonImmutable;

/**
 * Campos do DANFSe a partir da NFS-e autorizada — NT 008 v1.02 §2.1 (spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §8). Só dado que existe no XML ("não poderão ser
 * impressas informações que não constem do arquivo da NFS-e"); ausente vira "-".
 */
final class DanfseView
{
    private const EMPTY = '-';

    private const STATUSES = ['100' => 'NFS-e Gerada', '102' => 'NFS-e de Decisão Judicial', '103' => 'NFS-e Avulsa', '107' => 'NFS-e MEI'];

    private const ISS_TAXATION = ['1' => 'Operação Tributável', '2' => 'Imunidade', '3' => 'Exportação de Serviço', '4' => 'Não Incidência'];

    private const WITHHOLDING = ['1' => 'Não Retido', '2' => 'Retido pelo Tomador', '3' => 'Retido pelo Intermediário'];

    private const GENERATORS = ['1' => 'Sistema Próprio do Município', '2' => 'Sefin Nacional NFS-e'];

    private const PRODUCTION = '1';

    public function __construct(private readonly NfseDocumentReader $nfse) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'is_homologation' => $this->nfse->get('DPS/infDPS/tpAmb') !== self::PRODUCTION,
            'header' => $this->header(),
            'identification' => $this->identification(),
            'provider' => $this->provider(),
            'taker' => $this->taker(),
            'service' => $this->service(),
            'municipal' => $this->municipal(),
            'federal' => $this->federal(),
            'totals' => $this->totals(),
            'complementary' => $this->filled($this->nfse->get('xOutInf')),
        ];
    }

    /** @return array<string, string> */
    private function header(): array
    {
        return [
            'municipality' => sprintf('Município: %s / %s', $this->nfse->get('xLocEmi'), $this->nfse->get('emit/enderNac/UF')),
            'generator' => self::GENERATORS[$this->nfse->get('ambGer')] ?? self::EMPTY,
            'environment' => $this->nfse->get('DPS/infDPS/tpAmb') === self::PRODUCTION ? 'Produção' : 'Produção Restrita (Homologação)',
        ];
    }

    /** @return array<string, string> */
    private function identification(): array
    {
        return [
            'access_key' => $this->nfse->accessKey() ?? self::EMPTY,
            'number' => $this->filled($this->nfse->get('nNFSe')),
            'competence' => self::date($this->nfse->get('DPS/infDPS/dCompet'), 'd/m/Y'),
            'processed_at' => self::date($this->nfse->get('dhProc'), 'd/m/Y H:i:s'),
            'dps_number' => $this->filled($this->nfse->get('DPS/infDPS/nDPS')),
            'dps_series' => $this->filled($this->nfse->get('DPS/infDPS/serie')),
            'dps_issued_at' => self::date($this->nfse->get('DPS/infDPS/dhEmi'), 'd/m/Y H:i:s'),
            'issuer' => 'Prestador do Serviço',
            'status' => self::STATUSES[$this->nfse->get('cStat')] ?? self::EMPTY,
            'purpose' => 'NFS-e Regular',
        ];
    }

    /** @return array<string, string> */
    private function provider(): array
    {
        return [
            'document' => self::document($this->nfse->get('emit/CNPJ') ?: $this->nfse->get('emit/CPF')),
            'municipal_registration' => $this->filled($this->nfse->get('emit/IM')),
            'phone' => $this->filled($this->nfse->get('emit/fone')),
            'name' => $this->filled($this->nfse->get('emit/xNome')),
            'city' => sprintf('%s / %s', $this->nfse->get('xLocEmi'), $this->nfse->get('emit/enderNac/UF')),
            'codes' => sprintf('%s / %s', $this->nfse->get('emit/enderNac/cMun'), $this->nfse->get('emit/enderNac/CEP')),
            'address' => $this->address('emit/enderNac'),
            'email' => $this->filled($this->nfse->get('emit/email')),
        ];
    }

    /** @return array<string, string>|null nulo = "não identificado na NFS-e" */
    private function taker(): ?array
    {
        $document = $this->nfse->get('DPS/infDPS/toma/CNPJ') ?: $this->nfse->get('DPS/infDPS/toma/CPF');

        if ($document === '') {
            return null;
        }

        return [
            'document' => self::document($document),
            'name' => $this->filled($this->nfse->get('DPS/infDPS/toma/xNome')),
            'codes' => sprintf('%s / %s', $this->filled($this->nfse->get('DPS/infDPS/toma/end/endNac/cMun')), $this->filled($this->nfse->get('DPS/infDPS/toma/end/endNac/CEP'))),
            'address' => $this->address('DPS/infDPS/toma/end'),
        ];
    }

    /** @return array<string, string> */
    private function service(): array
    {
        return [
            'codes' => sprintf('%s / %s', $this->nfse->get('DPS/infDPS/serv/cServ/cTribNac'), $this->filled($this->nfse->get('DPS/infDPS/serv/cServ/cTribMun'))),
            'nbs' => $this->filled($this->nfse->get('DPS/infDPS/serv/cServ/cNBS')),
            'place' => $this->filled($this->nfse->get('xLocPrestacao')),
            'national_description' => $this->filled($this->nfse->get('xTribNac')),
            'description' => $this->filled($this->nfse->get('DPS/infDPS/serv/cServ/xDescServ')),
        ];
    }

    /** @return array<string, string> */
    private function municipal(): array
    {
        return [
            'taxation' => self::ISS_TAXATION[$this->nfse->get('DPS/infDPS/valores/trib/tribMun/tribISSQN')] ?? self::EMPTY,
            'incidence' => $this->filled($this->nfse->get('xLocIncid')),
            'unconditional_discount' => $this->money('DPS/infDPS/valores/vDescCondIncond/vDescIncond'),
            'base' => $this->money('valores/vBC'),
            'rate' => $this->nfse->get('valores/pAliqAplic') === '' ? self::EMPTY : NfseText::money($this->nfse->get('valores/pAliqAplic')).'%',
            'withholding' => self::WITHHOLDING[$this->nfse->get('DPS/infDPS/valores/trib/tribMun/tpRetISSQN')] ?? self::EMPTY,
            'iss' => $this->money('valores/vISSQN'),
        ];
    }

    /** @return array<string, string> */
    private function federal(): array
    {
        $federal = 'DPS/infDPS/valores/trib/tribFed/';

        return [
            'irrf' => $this->money($federal.'vRetIRRF'),
            'social_security' => $this->money($federal.'vRetCP'),
            'social_contributions' => $this->money($federal.'vRetCSLL'),
            'pis' => $this->money($federal.'piscofins/vPis'),
            'cofins' => $this->money($federal.'piscofins/vCofins'),
        ];
    }

    /** @return array<string, string> */
    private function totals(): array
    {
        return [
            'service' => $this->money('DPS/infDPS/valores/vServPrest/vServ'),
            'unconditional_discount' => $this->money('DPS/infDPS/valores/vDescCondIncond/vDescIncond'),
            'conditional_discount' => $this->money('DPS/infDPS/valores/vDescCondIncond/vDescCond'),
            'withholdings' => $this->money('valores/vTotalRet'),
            'net' => $this->money('valores/vLiq'),
        ];
    }

    /** Logradouro, número, complemento e bairro: filhos diretos de `enderNac` (emitente) e de `end` (tomador). */
    private function address(string $prefix): string
    {
        $parts = array_filter(array_map(fn (string $field): string => $this->nfse->get($prefix.'/'.$field), ['xLgr', 'nro', 'xCpl', 'xBairro']));

        return $parts === [] ? self::EMPTY : implode(', ', $parts);
    }

    private function money(string $path): string
    {
        $value = $this->nfse->get($path);

        return $value === '' ? self::EMPTY : 'R$ '.NfseText::money($value);
    }

    private function filled(string $value): string
    {
        return $value === '' ? self::EMPTY : $value;
    }

    private static function document(string $digits): string
    {
        return match (strlen($digits)) {
            14 => vsprintf('%s%s.%s%s%s.%s%s%s/%s%s%s%s-%s%s', str_split($digits)),
            11 => vsprintf('%s%s%s.%s%s%s.%s%s%s-%s%s', str_split($digits)),
            default => $digits === '' ? self::EMPTY : $digits,
        };
    }

    private static function date(string $value, string $format): string
    {
        return $value === '' ? self::EMPTY : CarbonImmutable::parse($value)->format($format);
    }
}
