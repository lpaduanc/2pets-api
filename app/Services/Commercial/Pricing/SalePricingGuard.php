<?php

namespace App\Services\Commercial\Pricing;

use App\Contracts\Sellable;
use App\Enums\DiscountType;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Regras de preço da venda — docs/gap-petflow/PLANO.md, 1.4:
 *
 * 1. Desconto efetivo da linha = (desconto da linha + parte do desconto do cabeçalho, rateada pelo
 *    maior resto de `Sale::netItemTotals`) ÷ bruto da linha. Acima do `max_discount_percent` do
 *    produto/serviço → 422.
 * 2. Líquido da linha abaixo do custo congelado → 422 na FINALIZAÇÃO, sem exceção por cargo
 *    (`BelowCostLineGuard`); a linha de brinde/cortesia/retorno nunca está abaixo do custo.
 *
 * Numa mudança (lançar/editar item, desconto do cabeçalho, marcar cortesia) só o teto de desconto
 * é julgado, e só na linha que a ação BARATEOU (líquido POR UNIDADE menor que antes, ou linha
 * nova — por unidade, porque subir a quantidade e baixar o preço juntos aumenta o líquido da
 * linha): editar uma linha não é recusado por causa de outra que ela não tocou. Remover item nunca
 * é barrado; a finalização (`assertReadyToReceive`) reconfere teto e custo em TODAS as linhas —
 * pega o desconto do cabeçalho redistribuído por uma remoção.
 */
final class SalePricingGuard
{
    /** Casas das contas intermediárias (líquido por unidade, desconto efetivo): nunca exibidas. */
    private const COMPARISON_SCALE = 10;

    public function __construct(private readonly BelowCostLineGuard $belowCost) {}

    /**
     * Líquido por unidade de cada linha antes da mudança, para saber depois quem ficou mais barato.
     *
     * @return array<int, BigDecimal>
     */
    public function snapshot(Sale $sale): array
    {
        $sale->load('items');

        return $this->netPerUnit($sale, $sale->netItemTotals());
    }

    /**
     * @param  array<int, BigDecimal>  $before  `snapshot()` tirado antes da mudança
     * @param  int|null  $addedItemId  linha recém-lançada, se a mudança foi um `addItem`
     */
    public function assertChange(Sale $sale, array $before, SalePricingChange $change, ?int $addedItemId = null): void
    {
        $sale->load('items.sellable');
        $after = $sale->netItemTotals();
        $afterPerUnit = $this->netPerUnit($sale, $after);

        foreach ($sale->items as $item) {
            if (isset($before[$item->id]) && $afterPerUnit[$item->id]->isGreaterThanOrEqualTo($before[$item->id])) {
                continue;
            }

            $this->assertLineDiscountWithinGross($item, $change->field('discount', $item->id, $addedItemId));
            $this->assertDiscountCeiling($item, $after[$item->id], $change->field('discount', $item->id, $addedItemId));
        }
    }

    /**
     * Recebimento: teto de desconto e custo em TODAS as linhas. 422 em `items.{id}.discount` /
     * `items.{id}.unit_price`.
     */
    public function assertReadyToReceive(Sale $sale): void
    {
        $sale->load('items.sellable');
        $net = $sale->netItemTotals();

        foreach ($sale->items as $item) {
            $this->assertDiscountCeiling($item, $net[$item->id], SalePricingChange::FINALIZATION->field('discount', $item->id, null));
            $this->belowCost->assertCoversCost($item, $net[$item->id], SalePricingChange::FINALIZATION->field('unit_price', $item->id, null));
        }
    }

    /**
     * Desconto do cabeçalho cabe no subtotal das linhas que não são cortesia (o `subtotal` já é
     * isso: a linha de cortesia tem total zero) e percentual vai até 100%. Antes o excesso era
     * cortado em silêncio (`DiscountType::moneyFor`) e zerava a venda sem marcação de cortesia.
     */
    public function assertHeaderDiscountFits(Sale $sale, DiscountType $type, BigNumber|string|float $value): void
    {
        $requested = Decimal::of($value);
        $limit = match ($type) {
            DiscountType::NONE => null,
            DiscountType::AMOUNT => Money::of($sale->items()->sum('total'))->toBigDecimal(),
            DiscountType::PERCENT => BigDecimal::of(100),
        };

        if ($limit === null || $requested->isLessThanOrEqualTo($limit)) {
            return;
        }

        throw ValidationException::withMessages(['discount_value' => __('pricing.header_discount_exceeds', [
            'limit' => $type === DiscountType::PERCENT ? '100%' : 'R$ '.self::display($limit),
        ])]);
    }

    /**
     * Desconto da linha não passa do bruto (quantidade × preço) — inclusive quando é a quantidade
     * que baixa. Antes `SaleItem::totalMoney` cortava em zero e a linha saía de graça sem ser cortesia.
     */
    private function assertLineDiscountWithinGross(SaleItem $item, string $field): void
    {
        $gross = Decimal::of($item->quantity)->multipliedBy(Decimal::of($item->unit_price));

        if ($item->isCourtesy() || Decimal::of($item->discount)->isLessThanOrEqualTo($gross)) {
            return;
        }

        throw ValidationException::withMessages([$field => __('pricing.line_discount_exceeds_gross', [
            'item' => $item->description,
            'gross' => self::display($gross),
        ])]);
    }

    private function assertDiscountCeiling(SaleItem $item, Money $net, string $field): void
    {
        $max = $item->sellable instanceof Sellable ? $item->sellable->maxDiscountPercent() : null;
        $gross = Decimal::of($item->quantity)->multipliedBy(Decimal::of($item->unit_price));

        if ($max === null || $item->isCourtesy() || ! $gross->isPositive()) {
            return;
        }

        $effective = $gross->minus($net->toBigDecimal())->multipliedBy(100)->dividedBy($gross, self::COMPARISON_SCALE, RoundingMode::HalfUp);

        if ($effective->isGreaterThan(Decimal::of($max))) {
            throw ValidationException::withMessages([$field => __('pricing.max_discount_exceeded', [
                'item' => $item->description,
                'effective' => self::display($effective),
                'max' => self::display(Decimal::of($max)),
            ])]);
        }
    }

    /**
     * @param  array<int, Money>  $net
     * @return array<int, BigDecimal>
     */
    private function netPerUnit(Sale $sale, array $net): array
    {
        $perUnit = [];

        foreach ($sale->items as $item) {
            $quantity = Decimal::of($item->quantity);
            $value = $net[$item->id]->toBigDecimal();
            $perUnit[$item->id] = $quantity->isPositive() ? $value->dividedBy($quantity, self::COMPARISON_SCALE, RoundingMode::HalfUp) : $value;
        }

        return $perUnit;
    }

    /** "12,5000" → "12,50": mensagem para gente, não para conta. */
    private static function display(BigDecimal $value): string
    {
        return number_format($value->toScale(2, RoundingMode::HalfUp)->toFloat(), 2, ',', '.');
    }
}
