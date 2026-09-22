<?php

namespace App\Services\Purchase;

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
     * @return array{supplier: array<string, string|null>, invoice: array<string, mixed>, items: list<array<string, mixed>>}
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
            $quantity = (float) $prod->qCom;
            $gross = (float) $prod->vProd;
            $discount = (float) $prod->vDesc;
            $batch = $prod->rastro[0] ?? null;

            $items[] = [
                'line' => (int) $det->attributes()['nItem'],
                'supplier_product_code' => $this->text($prod->cProd),
                'description_on_invoice' => $this->text($prod->xProd),
                // "SEM GTIN" é o valor oficial para item sem código de barras.
                'gtin' => $gtin !== null && ctype_digit($gtin) ? $gtin : null,
                'ncm' => $this->text($prod->NCM),
                'unit' => $this->text($prod->uCom),
                // Estoque é inteiro (ver migration do livro); quantidade fracionada é
                // arredondada e sinalizada para o usuário conferir na tela.
                'quantity' => (int) round($quantity),
                'fractional_quantity' => abs($quantity - round($quantity)) > 0.0001,
                'unit_cost' => round((float) $prod->vUnCom, 4),
                'discount' => round($discount, 2),
                'total_cost' => round($gross - $discount, 2),
                'batch' => $batch === null ? null : $this->text($batch->nLote),
                'expires_at' => $batch === null ? null : $this->text($batch->dVal),
            ];
        }

        return $items;
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
