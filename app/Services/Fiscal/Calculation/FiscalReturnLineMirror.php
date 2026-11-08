<?php

namespace App\Services\Fiscal\Calculation;

use App\DataTransferObjects\Fiscal\FiscalTaxRule;
use App\Support\Money\CumulativeShare;
use App\Support\Money\Money;
use App\Support\Money\Quantity;

/**
 * Item da NF-e de devolução de venda espelhando o item original congelado — spec
 * docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §3.5. Puro (golden `fiscal-devolucao-*`).
 *
 * Alvo = o item original recalculado com a MESMA regra (CST/CSOSN e alíquotas congelados) e o
 * acréscimo do original (`proportional`) ou zero (`keep`). Cada campo do alvo volta pelo rateio
 * acumulado (`CumulativeShare`): a última devolução leva o resto e Σ devoluções = alvo. O líquido
 * é o mesmo do reembolso em dinheiro (`SaleItem::refundFor`); desconto = bruto − líquido.
 */
final class FiscalReturnLineMirror
{
    /** Campos de imposto do alvo devolvidos pelo rateio acumulado. */
    private const TAX_AMOUNTS = ['icms_base', 'icms_amount', 'icms_sn_credit_amount', 'fcp_amount', 'pis_base', 'pis_amount', 'cofins_base', 'cofins_amount'];

    /** Alíquotas copiadas do alvo (não se rateia percentual). */
    private const TAX_RATES = ['icms_rate', 'icms_sn_credit_rate', 'fcp_rate', 'pis_rate', 'cofins_rate'];

    public function __construct(private readonly FiscalTaxCalculator $calculator) {}

    /**
     * @param  array<string, string|null>  $original  `quantity`, `gross_total`, `discount_share`, `surcharge_share`, `ipi_amount`, `icms_st_amount`
     * @param  array{before: Quantity, quantity: Quantity}  $slice  já devolvido antes e quanto agora
     * @return array<string, string>
     */
    public function mirror(array $original, FiscalTaxRule $rule, array $slice, bool $refundSurcharge): array
    {
        $net = Money::of($original['gross_total'])->minus(Money::of($original['discount_share']));
        $surcharge = $refundSurcharge ? Money::of($original['surcharge_share']) : Money::zero();
        $targetTaxes = $this->calculator->taxes($net->plus($surcharge), $rule);
        $share = fn (Money|string|null $amount): Money => CumulativeShare::of(Money::of($amount), $slice['before'], $slice['quantity'], Quantity::of($original['quantity']));

        $gross = $share($original['gross_total']);
        $returnedNet = $share($net);
        $returnedSurcharge = $share($surcharge);
        $ipi = $share($original['ipi_amount'] ?? '0');
        $icmsSt = $share($original['icms_st_amount'] ?? '0');

        return [
            'gross_total' => $gross->toString(),
            'discount_share' => $gross->minus($returnedNet)->toString(),
            'surcharge_share' => $returnedSurcharge->toString(),
            'ipi_amount' => $ipi->toString(),
            'icms_st_amount' => $icmsSt->toString(),
            'total' => $returnedNet->plus($returnedSurcharge)->plus($ipi)->plus($icmsSt)->toString(),
        ] + array_map(fn (string $field): string => $share($targetTaxes[$field])->toString(), array_combine(self::TAX_AMOUNTS, self::TAX_AMOUNTS))
          + array_intersect_key($targetTaxes, array_flip(self::TAX_RATES));
    }
}
