<?php

namespace App\Services\Commercial;

use App\Enums\CommissionCalculationBase;
use App\Models\CommissionRule;
use App\Models\SaleItem;
use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;

/**
 * Base de cálculo e valor da comissão de UM item de venda — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md, critérios de aceite.
 *
 * Exige `$item->sale` carregado com `items` e `receipts` para a base `net_of_card_fee` (rateio
 * da taxa da adquirente entre os itens da mesma venda) — quem chama (`CommissionSettlementService`)
 * já entrega o item nesse formato.
 */
final class CommissionCalculator
{
    /**
     * @return array{base_amount: float, commission_amount: float}
     */
    public function calculate(SaleItem $item, ?CommissionRule $rule): array
    {
        $base = $rule?->calculation_base ?? CommissionCalculationBase::GROSS;
        $baseAmount = $this->baseAmountFor($item, $base);

        return [
            'base_amount' => $baseAmount->toFloat(),
            'commission_amount' => $this->commissionAmountFor($item, $rule, $baseAmount)->toFloat(),
        ];
    }

    private function baseAmountFor(SaleItem $item, CommissionCalculationBase $base): Money
    {
        return match ($base) {
            CommissionCalculationBase::GROSS => Money::of(Decimal::of($item->quantity)->multipliedBy(Decimal::of($item->unit_price))),
            CommissionCalculationBase::NET_OF_DISCOUNT => Money::of($item->total),
            CommissionCalculationBase::NET_OF_CARD_FEE => $this->netOfCardFee($item),
            CommissionCalculationBase::MARGIN => $item->marginMoney(),
        };
    }

    /**
     * Regra com `percent` manda; sem `percent`, `fixed_amount` (flat, por linha); sem regra
     * nenhuma, cai no `commission_percent` congelado no próprio item (doc 08) sobre a base
     * `gross` — é a leitura de "sem regra cadastrada, usa o percentual do cadastro".
     */
    private function commissionAmountFor(SaleItem $item, ?CommissionRule $rule, Money $baseAmount): Money
    {
        if ($rule?->percent !== null) {
            return $baseAmount->percentage(Decimal::of($rule->percent));
        }

        if ($rule?->fixed_amount !== null) {
            return Money::of($rule->fixed_amount);
        }

        return $baseAmount->percentage(Decimal::of($item->commission_percent));
    }

    /**
     * Taxa da adquirente do RECEBIMENTO, rateada entre os itens da venda proporcionalmente ao
     * valor de cada um — "recebimento de R$ 100 com taxa de R$ 3 rateada entre 2 itens de
     * R$ 50 gera R$ 1,50 de dedução por item" (critério de aceite).
     */
    private function netOfCardFee(SaleItem $item): Money
    {
        $sale = $item->sale;
        $totalFee = Money::sum($sale->receipts->pluck('operator_fee'));
        $itemsTotal = Money::sum($sale->items->pluck('total'));

        if (! $totalFee->isPositive() || ! $itemsTotal->isPositive()) {
            return Money::of($item->total);
        }

        // Fórmula proporcional mantida na fase 0; o rateio por maior resto entre os itens é o
        // item 1.5 do docs/gap-petflow/PLANO.md. Aqui só a conta deixa de passar por float.
        $share = $totalFee->toBigDecimal()->multipliedBy(Decimal::of($item->total))
            ->dividedBy($itemsTotal->toBigDecimal(), 12, RoundingMode::HalfUp);

        return Money::of(Decimal::of($item->total)->minus($share));
    }
}
