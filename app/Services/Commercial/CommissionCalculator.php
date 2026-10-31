<?php

namespace App\Services\Commercial;

use App\Enums\CommissionCalculationBase;
use App\Models\CommissionRule;
use App\Models\SaleItem;
use App\Services\Commercial\Profitability\SaleAllocationService;
use App\Support\Money\Decimal;
use App\Support\Money\Money;

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
    public function __construct(private readonly SaleAllocationService $allocation) {}

    /**
     * @return array{base_amount: float, commission_amount: float}
     */
    public function calculate(SaleItem $item, ?CommissionRule $rule): array
    {
        $base = $rule?->calculation_base ?? CommissionCalculationBase::GROSS;
        $baseAmount = $this->baseAmountFor($item, $base);

        return [
            'base_amount' => $baseAmount->toFloat(),
            // Comissão nunca é negativa: base `margin` de linha com prejuízo (custo > receita, ou
            // brinde/cortesia, PLANO 1.4) daria valor negativo, que o fechamento descontaria do
            // profissional. A base continua a real (negativa) para a tela explicar o zero.
            'commission_amount' => $this->commissionAmountFor($item, $rule, $baseAmount)->max(Money::zero())->toFloat(),
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
     * Taxa da adquirente do RECEBIMENTO, rateada entre os itens da venda — "recebimento de R$ 100
     * com taxa de R$ 3 rateada entre 2 itens de R$ 50 gera R$ 1,50 de dedução por item" (critério
     * de aceite). O rateio é o do `SaleAllocationService` (PLANO 1.5): só a taxa que a clínica
     * ABSORVEU (com repasse ao cliente o acréscimo cobre a taxa), pelo maior resto sobre a receita
     * líquida da linha — o mesmo número que a rentabilidade usa.
     */
    private function netOfCardFee(SaleItem $item): Money
    {
        $share = $this->allocation->cardFeeShares($item->sale)[$item->id] ?? Money::zero();

        return Money::of($item->total)->minus($share);
    }
}
