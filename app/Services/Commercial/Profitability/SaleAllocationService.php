<?php

namespace App\Services\Commercial\Profitability;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\Money\Money;

/**
 * ÚNICA fonte dos rateios da venda — docs/gap-petflow/PLANO.md, 1.5 e seção 3, item 4: o desconto
 * do cabeçalho entre os itens (pesos = total da linha, já líquido do desconto dela) e a taxa da
 * adquirente entre os itens (pesos = receita líquida da linha). Sempre `Money::allocate` (maior
 * resto): Σ partes = total, centavo a centavo. Usado pela venda (`Sale::netItemTotals`), pela
 * comissão (`CommissionCalculator`, base `net_of_card_fee`) e pela rentabilidade — os três nunca
 * divergem.
 *
 * Linha de brinde/cortesia/retorno (PLANO 1.4) fica fora dos dois rateios: peso zero não basta,
 * porque `allocate` com todos os pesos zero divide em partes iguais.
 *
 * Sem dependências: o model chama direto (`new`), como `Service::unitCost` faz com o
 * `ServiceCostCalculator`.
 */
final class SaleAllocationService
{
    /**
     * Total de cada linha menos a parte dela no desconto do cabeçalho. Exige `items` carregados.
     *
     * @return array<int, Money> por `sale_items.id`
     */
    public function netItemTotals(Sale $sale): array
    {
        $totals = $this->lineTotals($sale);
        $courtesy = $this->courtesyIds($sale);

        foreach ($this->allocateAmong(Money::of($sale->discount_amount), $totals, $courtesy) as $itemId => $share) {
            $totals[$itemId] = $totals[$itemId]->minus($share);
        }

        return $totals;
    }

    /**
     * Parte de cada linha no desconto do cabeçalho.
     *
     * @return array<int, Money>
     */
    public function headerDiscountShares(Sale $sale): array
    {
        $totals = $this->lineTotals($sale);

        return $this->allocateAmong(Money::of($sale->discount_amount), $totals, $this->courtesyIds($sale)) + array_map(fn (): Money => Money::zero(), $totals);
    }

    /**
     * Taxa que a clínica ABSORVEU nos recebimentos: por recebimento, `operator_fee` menos o
     * acréscimo de repasse que o cliente pagou (plano 1.1), nunca negativo. Exige `receipts`.
     */
    public function absorbedCardFee(Sale $sale): Money
    {
        return Money::sum($sale->receipts->map(
            fn ($receipt): Money => Money::of($receipt->operator_fee)->minus($receipt->surcharge_amount ?? '0')->max(Money::zero())
        ));
    }

    /**
     * Taxa absorvida rateada pela receita líquida das linhas. Exige `items` e `receipts`.
     *
     * @return array<int, Money>
     */
    public function cardFeeShares(Sale $sale): array
    {
        $net = $this->netItemTotals($sale);

        return $this->allocateAmong($this->absorbedCardFee($sale), $net, $this->courtesyIds($sale)) + array_map(fn (): Money => Money::zero(), $net);
    }

    /**
     * `$amount` rateado entre as linhas com peso positivo que não estão em `$excluded`; as demais
     * não recebem nada (e não aparecem na resposta). Sem nenhuma linha elegível, nada é rateado.
     *
     * @param  array<int, Money>  $weights
     * @param  list<int>  $excluded
     * @return array<int, Money>
     */
    public function allocateAmong(Money $amount, array $weights, array $excluded = []): array
    {
        $eligible = array_filter(
            $weights,
            fn (Money $weight, int $itemId): bool => $weight->isPositive() && ! in_array($itemId, $excluded, true),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($eligible === [] || $amount->isZero()) {
            return [];
        }

        return $amount->allocate($eligible);
    }

    /** @return array<int, Money> */
    private function lineTotals(Sale $sale): array
    {
        $totals = [];

        foreach ($sale->items as $item) {
            $totals[$item->id] = $item->isCourtesy() ? Money::zero() : Money::of($item->total);
        }

        return $totals;
    }

    /** @return list<int> */
    private function courtesyIds(Sale $sale): array
    {
        return $sale->items->filter(fn (SaleItem $item): bool => $item->isCourtesy())->pluck('id')->values()->all();
    }
}
