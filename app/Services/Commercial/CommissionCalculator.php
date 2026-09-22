<?php

namespace App\Services\Commercial;

use App\Enums\CommissionCalculationBase;
use App\Models\CommissionRule;
use App\Models\SaleItem;

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
            'base_amount' => $baseAmount,
            'commission_amount' => $this->commissionAmountFor($item, $rule, $baseAmount),
        ];
    }

    private function baseAmountFor(SaleItem $item, CommissionCalculationBase $base): float
    {
        return match ($base) {
            CommissionCalculationBase::GROSS => round((float) $item->quantity * (float) $item->unit_price, 2),
            CommissionCalculationBase::NET_OF_DISCOUNT => (float) $item->total,
            CommissionCalculationBase::NET_OF_CARD_FEE => $this->netOfCardFee($item),
            CommissionCalculationBase::MARGIN => $item->marginAmount(),
        };
    }

    /**
     * Regra com `percent` manda; sem `percent`, `fixed_amount` (flat, por linha); sem regra
     * nenhuma, cai no `commission_percent` congelado no próprio item (doc 08) sobre a base
     * `gross` — é a leitura de "sem regra cadastrada, usa o percentual do cadastro".
     */
    private function commissionAmountFor(SaleItem $item, ?CommissionRule $rule, float $baseAmount): float
    {
        if ($rule?->percent !== null) {
            return round($baseAmount * ((float) $rule->percent / 100), 2);
        }

        if ($rule?->fixed_amount !== null) {
            return round((float) $rule->fixed_amount, 2);
        }

        $percent = $item->commission_percent === null ? 0.0 : (float) $item->commission_percent;

        return round($baseAmount * ($percent / 100), 2);
    }

    /**
     * Taxa da adquirente do RECEBIMENTO, rateada entre os itens da venda proporcionalmente ao
     * valor de cada um — "recebimento de R$ 100 com taxa de R$ 3 rateada entre 2 itens de
     * R$ 50 gera R$ 1,50 de dedução por item" (critério de aceite).
     */
    private function netOfCardFee(SaleItem $item): float
    {
        $sale = $item->sale;
        $totalFee = (float) $sale->receipts->sum('operator_fee');
        $itemsTotal = (float) $sale->items->sum('total');

        if ($totalFee <= 0 || $itemsTotal <= 0) {
            return (float) $item->total;
        }

        $share = $totalFee * ((float) $item->total / $itemsTotal);

        return round((float) $item->total - $share, 2);
    }
}
