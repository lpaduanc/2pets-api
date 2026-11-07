<?php

namespace App\Services\Stock\Projection;

use App\Support\Money\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Projeção de compra de UM produto (docs/gap-petflow/PLANO.md, 3.4), pura:
 *
 *   consumo_diário   = Σ saídas (venda + uso interno) na janela ÷ dias da janela
 *   dias_restantes   = estoque ÷ consumo_diário                     (estoque ≤ 0 → 0)
 *   data_ruptura     = hoje (data de negócio) + floor(dias_restantes)
 *   segurança        = z × σ_diário × √prazo   (z = 1,65; σ populacional dos dias da janela,
 *                      inclusive os sem saída), só quando o produto usa estoque de segurança
 *   sugestão         = ceil_múltiplo(consumo_diário × (prazo + cobertura) + segurança − estoque
 *                      − em_pedido_aberto, fator_compra), nunca negativa
 *
 * Consumo zero: dias_restantes e data_ruptura null, e a sugestão só cobre o segurança (que também
 * é zero sem variação). Contas internas com 10 casas; exibição: consumo e segurança com 4 casas,
 * dias com 1 casa, sugestão no múltiplo do fator. Casos em `tests/Fixtures/golden/purchase-projection.json`.
 */
final class PurchaseProjectionCalculator
{
    public const Z = '1.65';

    private const SCALE = 10;

    /**
     * @param  list<string|int|float>  $dailyDemand  uma posição por dia da janela (zeros inclusive)
     * @return array{daily_consumption: string, days_remaining: string|null, rupture_date: string|null, safety_stock: string, suggested_quantity: string}
     */
    public function project(array $dailyDemand, string $stock, string $openOrders, int $leadTimeDays, int $coverageDays, string $multiple, bool $useSafetyStock, CarbonImmutable $today): array
    {
        $days = max(1, count($dailyDemand));
        $values = array_map(fn ($v): BigDecimal => Decimal::of($v), $dailyDemand);
        $daily = BigDecimal::sum(BigDecimal::zero(), ...$values)->dividedBy($days, self::SCALE, RoundingMode::HalfUp);
        $stockValue = Decimal::of($stock);
        $safety = $useSafetyStock ? $this->safety($values, $daily, $leadTimeDays) : BigDecimal::zero();

        $remaining = $daily->isPositive()
            ? ($stockValue->isPositive() ? $stockValue->dividedBy($daily, self::SCALE, RoundingMode::HalfUp) : BigDecimal::zero())
            : null;

        $raw = $daily->multipliedBy($leadTimeDays + $coverageDays)->plus($safety)->minus($stockValue)->minus(Decimal::of($openOrders));

        return [
            'daily_consumption' => (string) $daily->toScale(4, RoundingMode::HalfUp),
            'days_remaining' => $remaining === null ? null : (string) $remaining->toScale(1, RoundingMode::HalfUp),
            'rupture_date' => $remaining === null ? null : $today->addDays($remaining->toScale(0, RoundingMode::Down)->toInt())->toDateString(),
            'safety_stock' => (string) $safety->toScale(4, RoundingMode::HalfUp),
            'suggested_quantity' => (string) $this->ceilToMultiple($raw, Decimal::of($multiple)),
        ];
    }

    /** z × σ × √prazo, com σ populacional dos dias da janela. @param  list<BigDecimal>  $values */
    private function safety(array $values, BigDecimal $mean, int $leadTimeDays): BigDecimal
    {
        $squares = array_map(fn (BigDecimal $v): BigDecimal => $v->minus($mean)->power(2), $values);
        $variance = BigDecimal::sum(BigDecimal::zero(), ...$squares)->dividedBy(max(1, count($values)), self::SCALE, RoundingMode::HalfUp);

        return BigDecimal::of(self::Z)->multipliedBy($variance->sqrt(self::SCALE, RoundingMode::HalfUp))->multipliedBy(BigDecimal::of($leadTimeDays)->sqrt(self::SCALE, RoundingMode::HalfUp))->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    private function ceilToMultiple(BigDecimal $raw, BigDecimal $multiple): BigDecimal
    {
        if (! $raw->isPositive() || ! $multiple->isPositive()) {
            return BigDecimal::zero();
        }

        return $raw->dividedBy($multiple, 0, RoundingMode::Up)->multipliedBy($multiple)->strippedOfTrailingZeros();
    }
}
