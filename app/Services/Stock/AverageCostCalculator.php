<?php

namespace App\Services\Stock;

use App\Support\Money\Decimal;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/**
 * Custo médio ponderado — docs/gap-simplesvet/06 ("manter custo médio ponderado em
 * `products.average_cost`") sobre o valor exato do estoque (docs/gap-petflow/PLANO.md, 0.2).
 * Classe pura, sem banco, para o `AverageCostTest` fixar os números.
 *
 * Invariante (o motivo da classe): Σ valor que entrou + Σ ajuste de custo positivo =
 * Σ valor que saiu + Σ ajuste de custo negativo + valor que ficou — exato, sem resíduo.
 *
 * - Saída pelo custo médio. Saída além do saldo (venda sem estoque, permitida no balcão) sai
 *   pelo médio vigente e deixa o VALOR negativo: são unidades devidas, já lançadas no custo da
 *   venda por uma estimativa.
 * - Entrada que cobre unidades devidas: elas custaram, de fato, a proporção da entrada; a
 *   diferença contra a estimativa lançada vira AJUSTE DE CUSTO (`$variance`), e o custo médio
 *   novo é só o das unidades que sobram — saldo negativo não distorce o médio.
 * - Reversão de compra tira o valor com que a compra entrou; se as unidades já foram vendidas
 *   (sobra pouco valor), a diferença também vira ajuste de custo.
 *
 * Todo valor é centavo (regra do dono do produto: 2 casas); o custo médio tem 4 casas (PLANO
 * seção 3, item 2) e é sempre derivado valor ÷ saldo, uma vez, `HALF_UP` — nunca acumulado.
 * Proporção e quantidade × médio viram centavo uma vez, `HALF_UP`; soma e subtração de centavos são exatas — e é por isso que a
 * invariante fecha: o que sai é subtraído do que ficou, nunca recalculado.
 */
final class AverageCostCalculator
{
    /**
     * Entrada de `$quantity` unidades que custaram `$value` no total (linha da nota).
     *
     * @return array{0: StockValuation, 1: BigDecimal} posição nova e ajuste de custo (valor
     *                                                 acrescentado ao estoque pela correção; negativo = custo a mais)
     */
    public function afterEntry(StockValuation $current, Quantity $quantity, BigNumber|string $value): array
    {
        $value = self::scaled(Decimal::of($value));
        $after = $current->quantity->plus($quantity);
        $enteringCost = self::divide($value, $quantity->toBigDecimal(), StockValuation::COST_SCALE);
        $variance = $current->quantity->isNegative() ? $this->owedUnitsVariance($current, $quantity, $value) : self::zero();
        $stockValue = $current->value->plus($value)->plus($variance);

        $average = $after->isPositive() ? self::averageOf($stockValue, $after) : $enteringCost;

        return [new StockValuation($after, $stockValue, $average), $variance];
    }

    /**
     * Saída pelo custo médio. Devolve a posição nova e o valor que saiu (custo da mercadoria).
     * Sair tudo leva o valor inteiro, sem resíduo de divisão.
     *
     * @return array{0: StockValuation, 1: BigDecimal}
     */
    public function afterExit(StockValuation $current, Quantity $quantity): array
    {
        $exitValue = match (true) {
            ! $current->quantity->isPositive() => self::atAverage($quantity, $current),
            $quantity->isLessThan($current->quantity) => self::proportion($current->value, $quantity, $current->quantity),
            default => $current->value->plus(self::atAverage($quantity->minus($current->quantity), $current)),
        };

        $after = new StockValuation($current->quantity->minus($quantity), $current->value->minus($exitValue), $current->averageCost);

        return [$after, $exitValue];
    }

    /**
     * Desfaz uma entrada (compra cancelada): tira as unidades com o MESMO valor com que
     * entraram. Quando o que sobra é zero/negativo, ou o valor que sobra ficaria negativo — as
     * unidades daquela compra já foram vendidas pelo médio —, o médio vigente é mantido e o
     * valor volta a ser saldo × médio; a diferença é o ajuste de custo.
     *
     * @return array{0: StockValuation, 1: BigDecimal}
     */
    public function afterReversal(StockValuation $current, Quantity $quantity, BigNumber|string $value): array
    {
        $after = $current->quantity->minus($quantity);
        $naive = $current->value->minus(self::scaled(Decimal::of($value)));

        if ($after->isPositive() && ! $naive->isNegative()) {
            return [new StockValuation($after, $naive, self::averageOf($naive, $after)), self::zero()];
        }

        $target = self::atAverage($after, $current);

        return [new StockValuation($after, $target, $current->averageCost), $target->minus($naive)];
    }

    /**
     * Reavaliação manual do custo médio (cadastro do produto): o valor passa a ser saldo × médio
     * novo, e a diferença é ajuste de custo — nunca valor criado sem rastro.
     *
     * @return array{0: StockValuation, 1: BigDecimal}
     */
    public function revalued(StockValuation $current, BigNumber|string $averageCost): array
    {
        $average = Decimal::of($averageCost)->toScale(StockValuation::COST_SCALE);
        $value = self::scaled($current->quantity->toBigDecimal()->multipliedBy($average));

        return [new StockValuation($current->quantity, $value, $average), $value->minus($current->value)];
    }

    /**
     * Unidades devidas cobertas por esta entrada: o custo verdadeiro delas é a proporção da
     * entrada; o lançado foi a proporção do valor negativo. Ajuste = lançado − verdadeiro.
     */
    private function owedUnitsVariance(StockValuation $current, Quantity $quantity, BigDecimal $value): BigDecimal
    {
        $owed = $current->quantity->abs();
        $covered = $owed->min($quantity);
        $estimated = $current->value->negated();

        $trueCost = $covered->isEqualTo($quantity) ? $value : self::proportion($value, $covered, $quantity);
        $recorded = $covered->isEqualTo($owed) ? $estimated : self::proportion($estimated, $covered, $owed);

        return $recorded->minus($trueCost);
    }

    private static function atAverage(Quantity $quantity, StockValuation $current): BigDecimal
    {
        return self::scaled($quantity->toBigDecimal()->multipliedBy($current->averageCost));
    }

    private static function proportion(BigDecimal $value, Quantity $part, Quantity $whole): BigDecimal
    {
        return self::divide($value->multipliedBy($part->toBigDecimal()), $whole->toBigDecimal(), StockValuation::VALUE_SCALE);
    }

    private static function averageOf(BigDecimal $value, Quantity $quantity): BigDecimal
    {
        return self::divide($value, $quantity->toBigDecimal(), StockValuation::COST_SCALE);
    }

    private static function divide(BigDecimal $dividend, BigDecimal $divisor, int $scale): BigDecimal
    {
        return $dividend->dividedBy($divisor, $scale, RoundingMode::HalfUp);
    }

    /** Valor vira centavo uma vez: quantidade (4 casas) × médio (4) pode ter até 8. */
    private static function scaled(BigDecimal $value): BigDecimal
    {
        return $value->toScale(StockValuation::VALUE_SCALE, RoundingMode::HalfUp);
    }

    private static function zero(): BigDecimal
    {
        return BigDecimal::zero()->toScale(StockValuation::VALUE_SCALE);
    }
}
