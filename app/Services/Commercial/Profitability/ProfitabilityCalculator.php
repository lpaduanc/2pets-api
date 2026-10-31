<?php

namespace App\Services\Commercial\Profitability;

use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * A conta da rentabilidade de uma venda finalizada (docs/gap-petflow/PLANO.md, 1.5), pura — sem
 * banco. Por item:
 *
 *   desc_cab = rateio do desconto do cabeçalho (pesos = total da linha)
 *   receita  = total − desc_cab
 *   custo    = round(qtd × custo unitário congelado)
 *   taxa     = rateio da taxa absorvida (pesos = receita)
 *   imposto  = round(receita × alíquota efetiva)
 *   comissão = a do item, nunca negativa
 *   MC       = receita − custo − taxa − imposto − comissão;  MC% = MC / receita (null se receita 0)
 *
 * Brinde/cortesia/retorno: receita, taxa e imposto zero, custo normal → MC = −custo, MC% null.
 * Devolução: cada valor do item é dividido entre a parte que ficou e a devolvida pelo maior resto
 * (`allocate` com pesos = quantidades), então ficou + devolvido = original, centavo a centavo.
 * Totais: somas; MC% da venda = ΣMC / Σreceita, nunca média dos percentuais (seção 3, item 5).
 * Casos de referência em `tests/Fixtures/golden/profitability.json`.
 */
final class ProfitabilityCalculator
{
    private const PERCENT_SCALE = 2;

    private const QUANTITY_SCALE = 4;

    public function __construct(private readonly SaleAllocationService $allocation) {}

    /**
     * @param  array{header_discount: string, card_fee: string, tax_percent: string|null, items: list<array<string, mixed>>}  $sale
     * @return array{items: list<array<string, mixed>>, totals: array<string, string|null>}
     */
    public function calculate(array $sale): array
    {
        $lines = collect($sale['items'])->keyBy('sale_item_id');
        $courtesy = $lines->filter(fn (array $line): bool => $line['courtesy_kind'] !== null)->keys()->all();
        $totals = $lines->map(fn (array $line): Money => in_array($line['sale_item_id'], $courtesy, true) ? Money::zero() : Money::of($line['total']))->all();
        $header = $this->allocation->allocateAmong(Money::of($sale['header_discount']), $totals, $courtesy);
        $net = array_map(fn (int $id): Money => $totals[$id]->minus($header[$id] ?? Money::zero()), array_combine(array_keys($totals), array_keys($totals)));
        $fees = $this->allocation->allocateAmong(Money::of($sale['card_fee']), $net, $courtesy);

        $items = $lines->map(fn (array $line, int $id): array => $this->item($line, $totals[$id], $header[$id] ?? Money::zero(), $fees[$id] ?? Money::zero(), $sale['tax_percent']))->values()->all();

        return ['items' => $items, 'totals' => $this->totals($items)];
    }

    /** @param  array<string, mixed>  $line */
    private function item(array $line, Money $gross, Money $headerShare, Money $fee, ?string $taxPercent): array
    {
        $isCourtesy = $line['courtesy_kind'] !== null;
        $revenue = $gross->minus($headerShare);
        $values = [
            'gross_total' => $gross,
            'allocated_header_discount' => $headerShare,
            'net_revenue' => $revenue,
            'cost_amount' => Money::of(Decimal::of($line['quantity'])->multipliedBy(Decimal::of($line['unit_cost']))),
            'card_fee_share' => $fee,
            'tax_amount' => $isCourtesy ? Money::zero() : $revenue->percentage(Decimal::of($taxPercent)),
            'commission_amount' => Money::of($line['commission'])->max(Money::zero()),
        ];
        $values = $this->keptPart($values, Decimal::of($line['quantity']), Decimal::of($line['returned_quantity'] ?? '0'));
        $margin = $values['net_revenue']->minus($values['cost_amount'])->minus($values['card_fee_share'])
            ->minus($values['tax_amount'])->minus($values['commission_amount']);

        return [
            'sale_item_id' => $line['sale_item_id'],
            'description' => $line['description'],
            'quantity' => (string) self::kept(Decimal::of($line['quantity']), Decimal::of($line['returned_quantity'] ?? '0'))->toScale(self::QUANTITY_SCALE),
            'courtesy_kind' => $line['courtesy_kind'],
            ...array_map(fn (Money $value): string => $value->toString(), $values),
            'contribution_margin' => $margin->toString(),
            'margin_percent' => self::percentOf($margin, $values['net_revenue']),
        ];
    }

    /**
     * Parte de cada valor que ficou com o cliente depois das devoluções.
     *
     * @param  array<string, Money>  $values
     * @return array<string, Money>
     */
    private function keptPart(array $values, BigDecimal $quantity, BigDecimal $returned): array
    {
        if (! $returned->isPositive()) {
            return $values;
        }

        $kept = self::kept($quantity, $returned);

        return array_map(fn (Money $value): Money => $value->allocate(['kept' => $kept, 'returned' => $quantity->minus($kept)])['kept'], $values);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, string|null>
     */
    private function totals(array $items): array
    {
        $columns = ['net_revenue', 'cost_amount', 'card_fee_share', 'tax_amount', 'commission_amount', 'contribution_margin'];
        $sums = array_combine($columns, array_map(fn (string $column): Money => Money::sum(array_column($items, $column)), $columns));

        return array_map(fn (Money $sum): string => $sum->toString(), $sums)
            + ['margin_percent' => self::percentOf($sums['contribution_margin'], $sums['net_revenue'])];
    }

    private static function kept(BigDecimal $quantity, BigDecimal $returned): BigDecimal
    {
        $kept = $quantity->minus($returned);

        return $kept->isNegative() ? BigDecimal::zero() : $kept;
    }

    private static function percentOf(Money $part, Money $base): ?string
    {
        if (! $base->isPositive()) {
            return null;
        }

        return (string) $part->toBigDecimal()->multipliedBy(100)->dividedBy($base->toBigDecimal(), self::PERCENT_SCALE, RoundingMode::HalfUp);
    }
}
