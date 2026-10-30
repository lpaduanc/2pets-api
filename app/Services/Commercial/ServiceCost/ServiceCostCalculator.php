<?php

namespace App\Services\Commercial\ServiceCost;

use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Custo do serviço pela ficha técnica de insumos — docs/gap-petflow/PLANO.md, 1.3:
 *
 *   custo = Σ(quantidade do insumo × custo médio do insumo) + custo direto fixo
 *
 * Classe pura (sem banco): é a MESMA conta do `Service::unitCost()` (congelado em
 * `sale_items.unit_cost`), do `GET services/{id}/supplies` e da prévia `POST services/cost-preview`
 * — a tela nunca mostra um custo e a venda congela outro. Casos de referência em
 * `tests/Fixtures/golden/service-cost.json` (cópia byte a byte no 2pets-pro).
 *
 * Arredondamento (seção 3): cada linha da ficha é um fato — `line_cost` arredonda UMA vez para
 * 4 casas (HALF_UP) e o custo do serviço é a soma exata das linhas mais o custo direto. Assim a
 * soma do que a tela lista é sempre o total que ela mostra. Margem em R$ arredonda para o centavo
 * só no fim; margem% e markup% saem com 4 casas, `null` quando o denominador é zero (serviço de
 * preço zero não tem margem%; serviço sem custo não tem markup).
 */
final class ServiceCostCalculator
{
    public const COST_SCALE = 4;

    public const PERCENT_SCALE = 4;

    private const HUNDRED = 100;

    /**
     * @param  list<array{product_id?: int|null, quantity: string|int|float, average_cost: string|int|float|null}>  $lines
     * @return array{items: list<array{product_id: int|null, unit_cost: string, line_cost: string}>, supplies_cost: string, direct_cost: string, unit_cost: string, price: string, margin_amount: string, margin_percent: string|null, markup_percent: string|null}
     */
    public function calculate(string|int|float|null $price, string|int|float|null $directCost, array $lines): array
    {
        $items = array_map(fn (array $line): array => $this->line($line), array_values($lines));
        $suppliesCost = array_reduce(
            $items,
            fn (BigDecimal $sum, array $item): BigDecimal => $sum->plus($item['line_cost']),
            BigDecimal::zero(),
        )->toScale(self::COST_SCALE);
        $direct = Money::of($directCost);
        $unitCost = $suppliesCost->plus($direct->toBigDecimal())->toScale(self::COST_SCALE);

        return [
            'items' => $items,
            'supplies_cost' => (string) $suppliesCost,
            'direct_cost' => $direct->toString(),
            'unit_cost' => (string) $unitCost,
            ...$this->margins(Money::of($price), $unitCost),
        ];
    }

    /**
     * @param  array{product_id?: int|null, quantity: string|int|float, average_cost: string|int|float|null}  $line
     * @return array{product_id: int|null, unit_cost: string, line_cost: string}
     */
    private function line(array $line): array
    {
        $unitCost = Decimal::round($line['average_cost'] ?? 0, self::COST_SCALE);
        $lineCost = Decimal::of($line['quantity'])->multipliedBy($unitCost)->toScale(self::COST_SCALE, RoundingMode::HalfUp);

        return [
            'product_id' => isset($line['product_id']) ? (int) $line['product_id'] : null,
            'unit_cost' => (string) $unitCost,
            'line_cost' => (string) $lineCost,
        ];
    }

    /**
     * margem% = (preço − custo) / preço × 100; markup% = (preço − custo) / custo × 100.
     *
     * @return array{price: string, margin_amount: string, margin_percent: string|null, markup_percent: string|null}
     */
    private function margins(Money $price, BigDecimal $unitCost): array
    {
        $margin = $price->toBigDecimal()->minus($unitCost);

        return [
            'price' => $price->toString(),
            'margin_amount' => Money::of($margin)->toString(),
            'margin_percent' => self::percentOf($margin, $price->toBigDecimal()),
            'markup_percent' => self::percentOf($margin, $unitCost),
        ];
    }

    private static function percentOf(BigDecimal $part, BigDecimal $base): ?string
    {
        if ($base->isZero()) {
            return null;
        }

        return (string) $part->multipliedBy(self::HUNDRED)->dividedBy($base, self::PERCENT_SCALE, RoundingMode::HalfUp);
    }
}
