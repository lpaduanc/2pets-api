<?php

namespace App\Services\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\DataTransferObjects\Fiscal\FiscalRuleContext;
use App\DataTransferObjects\Fiscal\FiscalTaxRule;
use App\DataTransferObjects\Fiscal\NfseServiceCode;
use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalDocumentOperation;
use App\Enums\TaxRegime;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Services\Fiscal\Calculation\FiscalCfopSelector;
use App\Services\Fiscal\Calculation\FiscalTaxCalculator;
use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Illuminate\Support\Collection;

/**
 * Itens congelados do documento de VENDA — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md
 * §3.1. O desconto do cabeçalho de cada linha vem de `Sale::netItemTotals` (fonte única,
 * `SaleAllocationService`); o acréscimo do documento é rateado aqui entre os itens dele. Produto
 * resolve perfil/CFOP/CST; serviço (NFS-e) não tem imposto por item.
 */
final class FiscalSaleLineBuilder
{
    private const DEFAULT_UNIT = 'UN';

    public function __construct(
        private readonly FiscalRuleResolver $rules,
        private readonly FiscalTaxCalculator $calculator,
        private readonly FiscalCfopSelector $cfops,
        private readonly FiscalPartyResolver $parties,
    ) {}

    /**
     * @param  Collection<int, SaleItem>  $items  linhas do documento (sem cortesia), com `sellable`
     * @return list<array<string, mixed>>
     */
    public function build(Sale $sale, FiscalDocumentKind $kind, Collection $items, Money $surcharge): array
    {
        $net = $sale->netItemTotals();
        $lines = $items->mapWithKeys(fn (SaleItem $item): array => [$item->id => ['gross' => self::gross($item), 'discount' => self::gross($item)->minus($net[$item->id])]])->all();
        $amounts = $this->calculator->distribute($lines, Money::zero(), $surcharge);
        $rules = $kind === FiscalDocumentKind::NFSE ? [] : $this->productRules($sale, $kind, $items);

        return $items->values()->map(
            fn (SaleItem $item, int $index): array => $this->row($item, $index + 1, $amounts[$item->id], $rules[$item->id] ?? null)
        )->all();
    }

    /**
     * @param  Collection<int, SaleItem>  $items
     * @return array<int, FiscalTaxRule|null>
     */
    private function productRules(Sale $sale, FiscalDocumentKind $kind, Collection $items): array
    {
        $problems = new FiscalIssueProblems;
        $owner = FiscalOwner::of($sale);
        $emitter = $this->parties->emitter($owner);
        $scope = $this->cfops->scope($emitter->state(), $this->parties->client($sale->client)?->state(), $kind);
        $context = new FiscalRuleContext($owner, TaxRegime::tryFrom((string) $emitter->taxRegime), FiscalDocumentOperation::SALE->cfopOperation($sale->fiscal_operation->isResale()), $scope);

        $items->each(fn (SaleItem $item) => self::assertNcm($item, $problems));
        $rules = $this->rules->resolve($context, $items->mapWithKeys(fn (SaleItem $item): array => [$item->id => ['product_id' => (int) $item->sellable_id, 'description' => (string) $item->description]])->all(), $problems);
        $problems->throwIfAny();

        return $rules;
    }

    private static function assertNcm(SaleItem $item, FiscalIssueProblems $problems): void
    {
        if ($item->sellable instanceof Product && $item->sellable->ncm === null) {
            $problems->add(sprintf('"%s" sem NCM no cadastro do produto.', $item->description));
        }
    }

    /**
     * @param  array{gross: Money, discount: Money, net: Money, surcharge: Money, total: Money}  $amounts
     * @return array<string, mixed>
     */
    private function row(SaleItem $item, int $lineNumber, array $amounts, ?FiscalTaxRule $rule): array
    {
        $product = $item->sellable instanceof Product ? $item->sellable : null;

        return [
            'line_number' => $lineNumber,
            'sale_item_id' => $item->id,
            'product_id' => $product?->id,
            'description' => (string) $item->description,
            'ncm' => $product?->ncm,
            'cest' => $product?->cest,
            'unit' => mb_substr((string) ($product?->unit_of_sale ?: self::DEFAULT_UNIT), 0, 10),
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'gross_total' => $amounts['gross']->toString(),
            'discount_share' => $amounts['discount']->toString(),
            'surcharge_share' => $amounts['surcharge']->toString(),
            'total' => $amounts['total']->toString(),
        ] + $this->calculator->taxes($amounts['total'], $rule) + ($rule?->toSnapshot() ?? []) + self::serviceCodes($item);
    }

    /**
     * Serviço: códigos da DPS congelados no item (spec fase4-transmissao-propria-nfse-nacional §5.3).
     *
     * @return array<string, string|null>
     */
    private static function serviceCodes(SaleItem $item): array
    {
        return $item->sellable instanceof Service ? NfseServiceCode::fromService($item->sellable)->toSnapshot() : [];
    }

    /** Quantidade × preço, virando centavo uma vez (o desconto da linha sai depois). */
    private static function gross(SaleItem $item): Money
    {
        return Money::of(Decimal::of($item->quantity)->multipliedBy(Decimal::of($item->unit_price)));
    }
}
