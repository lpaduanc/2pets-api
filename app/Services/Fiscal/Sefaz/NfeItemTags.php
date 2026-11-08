<?php

namespace App\Services\Fiscal\Sefaz;

use App\Exceptions\Fiscal\NfeXmlInvalid;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use NFePHP\NFe\Make;

/**
 * Item congelado do payload → `det` (`prod` + `imposto`) — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfe-nfce.md §5.2. Nada é recalculado: base, alíquota e valor de cada
 * imposto saem do item gravado na emissão (`FiscalDocumentItem`).
 *
 * ICMS-ST na venda (CST 10/30/70, CSOSN 201/202/203) está fora do escopo da fase 4 (spec
 * fase4-fiscal-sem-envio §1): o item é recusado localmente com o motivo em vez de sair com ST zerado.
 */
final class NfeItemTags
{
    private const NO_GTIN = 'SEM GTIN';

    private const DEFAULT_UNIT = 'UN';

    /** PIS/COFINS sem CST no perfil: 49 "outras operações de saída", com valores zerados. */
    private const DEFAULT_CONTRIBUTION_CST = '49';

    /** modBC 3 = valor da operação. */
    private const BASE_BY_OPERATION_VALUE = '3';

    /** Devolução de compra: percentual da mercadoria devolvida no `impostoDevol` (em aberto, §11). */
    private const RETURNED_PERCENT = '100.00';

    private const UNSUPPORTED_ST = ['10', '30', '70', '201', '202', '203'];

    /** CST/CSOSN que levam base, alíquota e valor do ICMS. */
    private const ICMS_WITH_VALUES = ['00', '20', '51', '90', '900'];

    /** @param  array<string, mixed>  $item */
    public function add(Make $nfe, array $item, int $number, ?string $descriptionOverride): void
    {
        $this->assertSupported($item, $number);
        $nfe->tagprod((object) $this->product($item, $number, $descriptionOverride));
        $nfe->tagimposto((object) ['item' => $number]);
        $this->icms($nfe, $item, $number);
        $nfe->tagPIS((object) $this->contribution($item, $number, 'pis', 'PIS'));
        $nfe->tagCOFINS((object) $this->contribution($item, $number, 'cofins', 'COFINS'));
        $this->returnedIpi($nfe, $item, $number);
    }

    /** @param  array<string, mixed>  $item */
    private function assertSupported(array $item, int $number): void
    {
        $code = (string) ($item['csosn'] ?? $item['cst']);

        if (in_array($code, self::UNSUPPORTED_ST, true)) {
            throw NfeXmlInvalid::because([sprintf('Item %d: CST/CSOSN %s exige ICMS-ST calculado, ainda não suportado na emissão.', $number, $code)]);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function product(array $item, int $number, ?string $descriptionOverride): array
    {
        $unit = (string) ($item['unit'] ?: self::DEFAULT_UNIT);
        // ST da devolução de compra vai em "outras despesas" do item (decisão em aberto, §11).
        $other = Money::of($item['surcharge_share'] ?? '0')->plus($item['icms_st_amount'] ?? '0');

        return array_filter([
            'item' => $number,
            'cProd' => (string) ($item['product_id'] ?? $number),
            'cEAN' => self::NO_GTIN,
            'xProd' => mb_substr($descriptionOverride ?? (string) $item['description'], 0, 120),
            'NCM' => $item['ncm'],
            'CEST' => $item['cest'] ?? null,
            'cBenef' => $item['benefit_code'] ?? null,
            'CFOP' => $item['cfop'],
            'uCom' => $unit,
            'qCom' => self::decimal($item['quantity'], 4),
            'vUnCom' => self::decimal($item['unit_price'], 4),
            'vProd' => Money::of($item['gross_total'])->toString(),
            'cEANTrib' => self::NO_GTIN,
            'uTrib' => $unit,
            'qTrib' => self::decimal($item['quantity'], 4),
            'vUnTrib' => self::decimal($item['unit_price'], 4),
            'vDesc' => self::positiveMoney($item['discount_share'] ?? '0'),
            'vOutro' => $other->isPositive() ? $other->toString() : null,
            'indTot' => 1,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    /** @param  array<string, mixed>  $item */
    private function icms(Make $nfe, array $item, int $number): void
    {
        if ($item['csosn'] !== null) {
            $nfe->tagICMSSN((object) ($this->icmsBase($item, $number) + ['CSOSN' => $item['csosn']] + $this->simplesCredit($item)));

            return;
        }

        $nfe->tagICMS((object) ($this->icmsBase($item, $number) + ['CST' => $item['cst']]));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function icmsBase(array $item, int $number): array
    {
        $values = ['item' => $number, 'orig' => (string) $item['origin']];
        $code = (string) ($item['csosn'] ?? $item['cst']);

        if (! in_array($code, self::ICMS_WITH_VALUES, true)) {
            return $values;
        }

        return $values + array_filter([
            'modBC' => self::BASE_BY_OPERATION_VALUE,
            'pRedBC' => Money::of($item['icms_base_reduction_percent'] ?? '0')->isPositive() ? self::decimal($item['icms_base_reduction_percent'], 4) : null,
            'vBC' => Money::of($item['icms_base'])->toString(),
            'pICMS' => self::decimal($item['icms_rate'], 4),
            'vICMS' => Money::of($item['icms_amount'])->toString(),
            'pFCP' => self::positiveMoney($item['fcp_amount'] ?? '0') === null ? null : self::decimal($item['fcp_rate'], 4),
            'vFCP' => self::positiveMoney($item['fcp_amount'] ?? '0'),
        ], fn ($value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, string>
     */
    private function simplesCredit(array $item): array
    {
        if ($item['csosn'] !== '101') {
            return [];
        }

        return ['pCredSN' => self::decimal($item['icms_sn_credit_rate'], 4), 'vCredICMSSN' => Money::of($item['icms_sn_credit_amount'])->toString()];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function contribution(array $item, int $number, string $prefix, string $tag): array
    {
        $cst = (string) ($item["{$prefix}_cst"] ?: self::DEFAULT_CONTRIBUTION_CST);

        return [
            'item' => $number,
            'CST' => $cst,
            'vBC' => Money::of($item["{$prefix}_base"] ?? '0')->toString(),
            "p{$tag}" => self::decimal($item["{$prefix}_rate"] ?? '0', 4),
            "v{$tag}" => Money::of($item["{$prefix}_amount"] ?? '0')->toString(),
        ];
    }

    /**
     * IPI devolvido (devolução de compra) — grupo `impostoDevol`, que soma em `vIPIDevol`.
     *
     * @param  array<string, mixed>  $item
     */
    private function returnedIpi(Make $nfe, array $item, int $number): void
    {
        $ipi = self::positiveMoney($item['ipi_amount'] ?? '0');

        if ($ipi !== null) {
            $nfe->tagimpostoDevol((object) ['item' => $number, 'pDevol' => self::RETURNED_PERCENT, 'vIPIDevol' => $ipi]);
        }
    }

    /** Decimal exato (sem float) na escala do layout: qCom/vUnCom 4 casas, alíquotas 4. */
    private static function decimal(mixed $value, int $scale): string
    {
        return (string) BigDecimal::of((string) ($value ?? '0'))->toScale($scale, RoundingMode::HalfUp);
    }

    private static function positiveMoney(mixed $value): ?string
    {
        $money = Money::of((string) $value);

        return $money->isPositive() ? $money->toString() : null;
    }
}
