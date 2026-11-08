<?php

namespace App\Services\Purchase;

use App\Support\Money\CumulativeShare;
use App\Support\Money\Money;
use App\Support\Money\Quantity;

/**
 * Quanto uma devolução ao fornecedor leva de cada valor do item da compra — spec
 * docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §3.6. Puro (golden `fiscal-compra-dev-01`).
 *
 * Produtos (bruto da nota), desconto, IPI, ICMS-ST e o valor que sai do estoque (custo real da
 * entrada) pelo rateio acumulado na quantidade da compra: devolver tudo, em quantas vezes for,
 * devolve exatamente o da nota. Frete, seguro e outras despesas NÃO voltam (decisão D10).
 * `total = produtos − desconto + IPI + ST` (o vNF da devolução).
 */
final class PurchaseReturnLineCalculator
{
    /**
     * @param  array<string, string|null>  $item  `quantity`, `total_cost` (líquido), `discount`, `ipi_amount`, `icms_st_amount`, `landed_total_cost`
     * @return array{products_total: string, discount_share: string, ipi_share: string, icms_st_share: string, stock_value: string, total: string}
     */
    public function line(array $item, Quantity $before, Quantity $quantity): array
    {
        $share = fn (Money $amount): Money => CumulativeShare::of($amount, $before, $quantity, Quantity::of($item['quantity']));
        $products = $share(Money::of($item['total_cost'])->plus(Money::of($item['discount'] ?? '0')));
        $discount = $share(Money::of($item['discount'] ?? '0'));
        $ipi = $share(Money::of($item['ipi_amount'] ?? '0'));
        $icmsSt = $share(Money::of($item['icms_st_amount'] ?? '0'));

        return [
            'products_total' => $products->toString(),
            'discount_share' => $discount->toString(),
            'ipi_share' => $ipi->toString(),
            'icms_st_share' => $icmsSt->toString(),
            'stock_value' => $share(Money::of($item['landed_total_cost'] ?? '0'))->toString(),
            'total' => $products->minus($discount)->plus($ipi)->plus($icmsSt)->toString(),
        ];
    }
}
