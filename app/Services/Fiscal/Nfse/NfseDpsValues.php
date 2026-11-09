<?php

namespace App\Services\Fiscal\Nfse;

use App\DataTransferObjects\Fiscal\FiscalInvoicePayload;
use App\DataTransferObjects\Fiscal\NfseDpsContext;
use App\DataTransferObjects\Fiscal\NfseServiceCode;
use App\Exceptions\Fiscal\NfeXmlInvalid;
use App\Support\Money\Money;
use DOMElement;

/**
 * Grupos `serv` e `valores` da DPS — spec docs/gap-petflow/specs/
 * fase4-transmissao-propria-nfse-nacional.md §5.3/§5.4.
 *
 * Dinheiro: `vServ = Σ bruto + Σ acréscimo` e `vDescIncond = Σ desconto`, ambos já rateados ao
 * centavo pelo `FiscalSaleLineBuilder`; `vServ − vDescIncond` é CONFERIDO contra o total do
 * documento — um centavo de diferença é rejeição local, nunca ajuste.
 *
 * `pAliq` nunca vai: o município de incidência (o do emitente) tem convênio ativo (E0038) e sem
 * retenção as regras E0617/E0625/E0635 proíbem informar a alíquota — a Sefin aplica a do município.
 */
final class NfseDpsValues
{
    private const TAXABLE_OPERATION = '1';

    private const NOT_WITHHELD = '1';

    private const DESCRIPTION_MAX = 1000;

    public function append(NfseXmlWriter $writer, DOMElement $info, FiscalInvoicePayload $payload, NfseDpsContext $context): void
    {
        $this->service($writer, $info, $payload, $context);
        $this->amounts($writer, $info, $payload, $context);
    }

    private function service(NfseXmlWriter $writer, DOMElement $info, FiscalInvoicePayload $payload, NfseDpsContext $context): void
    {
        $code = self::serviceCode($payload->items);
        $service = $writer->group($info, 'serv');
        $writer->add($writer->group($service, 'locPrest'), 'cLocPrestacao', $context->cityCode);
        $codes = $writer->group($service, 'cServ');
        $writer->add($codes, 'cTribNac', $code->nationalTaxCode);
        $writer->add($codes, 'cTribMun', $code->municipalTaxCode);
        $description = NfseXmlWriter::text(self::description($payload->items), self::DESCRIPTION_MAX) ?? 'Serviço';

        if (! $context->layout->describesServiceLast()) {
            $writer->add($codes, 'xDescServ', $description);
        }

        $writer->add($codes, 'cNBS', $code->nbsCode);

        if ($context->layout->describesServiceLast()) {
            $writer->add($codes, 'xDescServ', $description);
        }
    }

    private function amounts(NfseXmlWriter $writer, DOMElement $info, FiscalInvoicePayload $payload, NfseDpsContext $context): void
    {
        [$service, $discount] = self::serviceAndDiscount($payload);
        $values = $writer->group($info, 'valores');
        $writer->add($writer->group($values, 'vServPrest'), 'vServ', $service->toString());

        if ($discount->isPositive()) {
            $writer->add($writer->group($values, 'vDescCondIncond'), 'vDescIncond', $discount->toString());
        }

        $taxes = $writer->group($values, 'trib');
        $municipal = $writer->group($taxes, 'tribMun');
        $writer->add($municipal, 'tribISSQN', self::TAXABLE_OPERATION);
        $writer->add($municipal, 'tpRetISSQN', self::NOT_WITHHELD);
        $this->approximateTaxes($writer, $writer->group($taxes, 'totTrib'), $context->approximateTaxes);
    }

    /** @param  array<string, mixed>  $choice */
    private function approximateTaxes(NfseXmlWriter $writer, DOMElement $total, array $choice): void
    {
        foreach ($choice as $name => $value) {
            if (! is_array($value)) {
                $writer->add($total, $name, (string) $value);

                continue;
            }

            $group = $writer->group($total, $name);
            array_walk($value, fn (string $percent, string $child) => $writer->add($group, $child, $percent));
        }
    }

    /** @return array{0: Money, 1: Money} */
    private static function serviceAndDiscount(FiscalInvoicePayload $payload): array
    {
        $service = Money::of($payload->totals['products'])->plus($payload->totals['other']);
        $discount = Money::of($payload->totals['discount']);

        if (! $service->minus($discount)->isEqualTo($payload->totals['total'])) {
            throw NfeXmlInvalid::because([sprintf('Valor do serviço (%s) menos desconto (%s) difere do total do documento (%s).', $service, $discount, $payload->totals['total'])]);
        }

        return [$service, $discount];
    }

    /**
     * Código único do documento (a venda já separa uma NFS-e por código); serviço sem cTribNac
     * não tem como ir à Sefin.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private static function serviceCode(array $items): NfseServiceCode
    {
        $codes = array_map(fn (array $item): NfseServiceCode => NfseServiceCode::fromItem($item), $items);
        $missing = array_values(array_filter($items, fn (array $item): bool => blank($item['national_tax_code'] ?? null)));

        if ($missing !== []) {
            throw NfeXmlInvalid::because([sprintf('Serviço sem código de tributação nacional (cTribNac) no cadastro: %s.', implode(', ', array_column($missing, 'description')))]);
        }

        if (count(array_unique(array_map(fn (NfseServiceCode $code): string => $code->key(), $codes))) > 1) {
            throw NfeXmlInvalid::because(['Serviços com códigos de tributação diferentes não cabem na mesma NFS-e.']);
        }

        return $codes[0];
    }

    /** @param  list<array<string, mixed>>  $items */
    private static function description(array $items): string
    {
        return implode('; ', array_map(
            fn (array $item): string => sprintf('%s (%s x R$ %s)', $item['description'], NfseText::quantity((string) $item['quantity']), NfseText::money((string) $item['unit_price'])),
            $items,
        ));
    }
}
