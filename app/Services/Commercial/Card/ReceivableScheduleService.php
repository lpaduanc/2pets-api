<?php

namespace App\Services\Commercial\Card;

use App\Enums\ReceivableStatus;
use App\Models\Receivable;
use App\Models\SaleReceipt;
use App\Services\Finance\DueDateService;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Agenda de recebíveis de um recebimento de cartão — docs/gap-petflow/PLANO.md, 1.1 (golden
 * `mdr-01`: R$ 100,00 em 3x a 2,99% → bruto 33,34 · 33,33 · 33,33; taxa 1,00 · 1,00 · 0,99;
 * líquido Σ 97,01).
 *
 * - A taxa incide sobre o TOTAL (percentual arredondado uma vez + fixa), como a adquirente cobra.
 * - O bruto é dividido com a diferença de centavo na 1ª parcela (convenção das adquirentes).
 * - A taxa é rateada entre as parcelas pelo maior resto, pesada pelo bruto: Σ taxa das parcelas
 *   = taxa do recebimento, e Σ líquido = líquido do recebimento — nenhum centavo some.
 * - Data de cada parcela: recebimento + dias da 1ª + (i − 1) × intervalo, no próximo dia útil.
 */
final class ReceivableScheduleService
{
    public function __construct(private readonly DueDateService $dueDates) {}

    /** @return list<PlannedReceivable> */
    public function plan(Money $amount, int $installments, FeeTerms $terms, CarbonImmutable $receivedAt, ?int $organizationId = null): array
    {
        $gross = $amount->splitInInstallments(max(1, $installments));
        $fees = $this->feeFor($amount, $terms)->allocate($gross);
        $plan = [];

        foreach ($gross as $index => $part) {
            $days = $terms->firstSettlementDays + ($index * $terms->intervalDays);
            $plan[] = new PlannedReceivable(
                $index + 1,
                $part,
                $fees[$index],
                $part->minus($fees[$index]),
                $this->dueDates->nextBusinessDay($receivedAt->startOfDay()->addDays($days), $organizationId),
            );
        }

        return $plan;
    }

    /** Taxa total do recebimento: percentual sobre o total (um arredondamento) + taxa fixa. */
    public function feeFor(Money $amount, FeeTerms $terms): Money
    {
        return $amount->percentage($terms->feePercent)->plus($terms->feeFixed)->min($amount);
    }

    /**
     * Grava as parcelas planejadas do recebimento.
     *
     * @param  list<PlannedReceivable>  $plan
     */
    public function persist(SaleReceipt $receipt, array $plan): void
    {
        $sale = $receipt->sale;

        foreach ($plan as $planned) {
            Receivable::create([
                'organization_id' => $sale->organization_id,
                'professional_id' => $sale->professional_id,
                'sale_receipt_id' => $receipt->id,
                'payment_method_id' => $receipt->payment_method_id,
                'card_brand' => $receipt->card_brand,
                'installment_number' => $planned->number,
                'installments_count' => count($plan),
                'gross_amount' => $planned->gross->toString(),
                'fee_amount' => $planned->fee->toString(),
                'net_amount' => $planned->net->toString(),
                'expected_date' => $planned->expectedDate->toDateString(),
                'status' => ReceivableStatus::PENDING,
            ]);
        }
    }

    /**
     * Soma do plano — o que vai para `operator_fee`/`net_amount` do recebimento.
     *
     * @param  list<PlannedReceivable>  $plan
     * @return array{fee: Money, net: Money}
     */
    public function totals(array $plan): array
    {
        return [
            'fee' => Money::sum(array_map(fn (PlannedReceivable $p): Money => $p->fee, $plan)),
            'net' => Money::sum(array_map(fn (PlannedReceivable $p): Money => $p->net, $plan)),
        ];
    }
}
