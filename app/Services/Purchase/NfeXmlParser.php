<?php

namespace App\Services\Purchase;

use App\Services\Stock\StockValuation;
use App\Support\Money\Decimal;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use SimpleXMLElement;

/**
 * Leitura do XML de NF-e modelo 55 (`nfeProc` ou `NFe` solto) para um array neutro. Só lê;
 * casamento com o cadastro é do `NfeXmlImportService`.
 *
 * Segurança: `LIBXML_NONET` e sem `LIBXML_NOENT` — entidade externa não é resolvida (XXE), e o
 * arquivo vem de terceiro (o fornecedor), não do usuário.
 */
final class NfeXmlParser
{
    private const NS = 'http://www.portalfiscal.inf.br/nfe';

    /**
     * @return array{supplier: array<string, string|null>, invoice: array<string, mixed>, costs: array<string, string>, items: list<array<string, mixed>>}
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        abort_if($root === false, 422, 'Arquivo não é um XML válido.');

        $root->registerXPathNamespace('n', self::NS);
        $infNFe = $root->xpath('//n:infNFe')[0] ?? null;
        abort_if($infNFe === null, 422, 'XML não é uma NF-e (modelo 55): nó infNFe ausente.');

        $nfe = $infNFe->children(self::NS);
        $ide = $nfe->ide;
        $emit = $nfe->emit;
        $address = $emit->enderEmit;
        $totals = $nfe->total->ICMSTot;

        $key = $this->protocolKey($root) ?? preg_replace('/\D/', '', (string) $infNFe->attributes()['Id']);

        return [
            'supplier' => [
                'legal_name' => $this->text($emit->xNome),
                'trade_name' => $this->text($emit->xFant),
                'document' => $this->text($emit->CNPJ) ?? $this->text($emit->CPF),
                'state_registration' => $this->text($emit->IE),
                'phone' => $this->text($address->fone),
                'address_zip' => $this->text($address->CEP),
                'address_street' => $this->text($address->xLgr),
                'address_number' => $this->text($address->nro),
                'address_complement' => $this->text($address->xCpl),
                'address_district' => $this->text($address->xBairro),
                'address_city' => $this->text($address->xMun),
                'address_state' => $this->text($address->UF),
            ],
            'invoice' => [
                'number' => $this->text($ide->nNF),
                'series' => $this->text($ide->serie),
                'key' => strlen((string) $key) === 44 ? $key : null,
                'issued_at' => substr((string) ($this->text($ide->dhEmi) ?? $this->text($ide->dEmi) ?? ''), 0, 10) ?: null,
                'total_products' => (float) $totals->vProd,
                'total_freight' => (float) $totals->vFrete,
                'total_discount' => (float) $totals->vDesc,
                'total' => (float) $totals->vNF,
            ],
            'costs' => $this->costTotals($totals),
            'items' => $this->items($nfe),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(SimpleXMLElement $nfe): array
    {
        $items = [];

        foreach ($nfe->det as $det) {
            $prod = $det->prod;
            $gtin = $this->text($prod->cEAN);
            // Texto exato da nota (float perderia fração). `qCom` tem até 4 casas; o valor que
            // vale é o da LINHA (`vProd`, centavos) — o `vUnCom` pode ter até 10 casas e só vira o
            // custo unitário de 4 casas (PLANO seção 3, item 2).
            $quantity = Quantity::of($this->text($prod->qCom) ?? '0');
            $gross = Money::of($this->text($prod->vProd) ?? '0');
            $discount = Money::of($this->text($prod->vDesc) ?? '0');
            $batch = $prod->rastro[0] ?? null;

            $items[] = [
                'line' => (int) $det->attributes()['nItem'],
                'supplier_product_code' => $this->text($prod->cProd),
                'description_on_invoice' => $this->text($prod->xProd),
                // "SEM GTIN" é o valor oficial para item sem código de barras.
                'gtin' => $gtin !== null && ctype_digit($gtin) ? $gtin : null,
                'ncm' => $this->text($prod->NCM),
                'unit' => $this->text($prod->uCom),
                // Estoque decimal desde a migration 2026_11_05_100000: a quantidade da nota entra
                // como veio. `fractional_quantity` avisa a tela de que o produto precisa aceitar
                // casas decimais (`decimal_places_allowed`) para receber este item.
                'quantity' => $quantity->toString(),
                'fractional_quantity' => $quantity->decimalPlaces() > 0,
                'unit_cost' => (string) Decimal::round($this->text($prod->vUnCom) ?? '0', StockValuation::COST_SCALE),
                // A compra grava ESTE valor como bruto da linha, não quantidade × custo de 2
                // casas — que poderia diferir do total da nota em centavos.
                'gross_total' => $gross->toString(),
                'discount' => $discount->toString(),
                'total_cost' => $gross->minus($discount)->toString(),
                'batch' => $batch === null ? null : $this->text($batch->nLote),
                'expires_at' => $batch === null ? null : $this->text($batch->dVal),
            ] + $this->itemCosts($det);
        }

        return $items;
    }

    /**
     * Totais da nota que compõem o custo real (plano 1.2), em texto exato de 2 casas. Os de
     * frete/seguro/outras/desconto são o que se rateia quando o item não traz o próprio valor.
     *
     * @return array<string, string>
     */
    private function costTotals(SimpleXMLElement $totals): array
    {
        $fields = [
            'total_freight' => 'vFrete', 'total_insurance' => 'vSeg', 'total_other' => 'vOutro',
            'total_discount' => 'vDesc', 'total_ipi' => 'vIPI', 'total_icms_st' => 'vST',
        ];

        return array_map(fn (string $tag): string => Money::of($this->text($totals->{$tag}) ?? '0')->toString(), $fields);
    }

    /**
     * Valores do item que entram no custo real. `null` = a nota não detalhou no item (o
     * `NfeXmlImportService` decide se rateia o total). ST: qualquer grupo ICMS (10, 30, 70, 90,
     * SN201, SN202, SN900) traz `vICMSST`; ST retido antes (ICMS60) já está no preço e não soma.
     *
     * @return array<string, string|null>
     */
    private function itemCosts(SimpleXMLElement $det): array
    {
        $det->registerXPathNamespace('n', self::NS);

        return [
            'freight_share' => $this->moneyAt($det, 'n:prod/n:vFrete'),
            'insurance_share' => $this->moneyAt($det, 'n:prod/n:vSeg'),
            'other_share' => $this->moneyAt($det, 'n:prod/n:vOutro'),
            'ipi_amount' => $this->moneyAt($det, 'n:imposto/n:IPI/n:IPITrib/n:vIPI'),
            'icms_st_amount' => $this->moneyAt($det, 'n:imposto/n:ICMS/*/n:vICMSST'),
        ];
    }

    private function moneyAt(SimpleXMLElement $node, string $path): ?string
    {
        $value = $this->text($node->xpath($path)[0] ?? null);

        return $value === null ? null : Money::of($value)->toString();
    }

    private function protocolKey(SimpleXMLElement $root): ?string
    {
        $node = $root->xpath('//n:protNFe/n:infProt/n:chNFe')[0] ?? null;

        return $node === null ? null : trim((string) $node);
    }

    private function text(?SimpleXMLElement $node): ?string
    {
        if ($node === null) {
            return null;
        }

        $value = trim((string) $node);

        return $value === '' ? null : $value;
    }
}
