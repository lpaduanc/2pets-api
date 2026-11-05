<?php

namespace App\Services\Finance\ClientCredit;

use App\Models\ClientCreditInstallment;
use App\Models\ClientCreditPaymentItem;
use App\Models\ClientCreditPlan;
use App\Models\Organization;
use App\Models\Sale;
use App\Models\SaleReceipt;
use App\Models\User;
use App\Support\Money\Money;

/**
 * Ciclo de vida do plano de crediário (PLANO 2.2): nasce no `complete` da venda a prazo (com
 * parcelas e 1º vencimento), desfaz o principal de um recebimento estornado (2.3) e morre com o
 * cancelamento da venda.
 *
 * Estorno de recebimento de crediário: o principal volta às parcelas (o recebimento era só o
 * principal); os encargos já recebidos ficam — são outro fato (receita financeira do atraso), e o
 * estorno deles é ajuste manual no financeiro. Cancelar a venda cancela o plano e as parcelas
 * abertas; os recebimentos são estornados pelo próprio cancelamento da venda.
 */
final class ClientCreditPlanService
{
    public const DEFAULT_POLICY = ['late_fee_percent' => '2.00', 'monthly_interest_percent' => '1.0000', 'grace_days' => 0];

    public function __construct(private readonly ClientCreditCalculator $calculator) {}

    public function createForSale(Sale $sale, User $user, int $installments, string $firstDueDate): ClientCreditPlan
    {
        $policy = $this->policyOf($sale->organization_id === null ? null : Organization::find($sale->organization_id));
        $total = $sale->amountDueMoney();
        $plan = ClientCreditPlan::create([
            'organization_id' => $sale->organization_id,
            'professional_id' => $sale->professional_id,
            'client_id' => $sale->client_id,
            'sale_id' => $sale->id,
            'total' => $total->toString(),
            'installments_count' => $installments,
            'first_due_date' => $firstDueDate,
            ...$policy,
            'status' => ClientCreditPlan::ACTIVE,
            'created_by' => $user->id,
        ]);

        foreach ($this->calculator->schedule($total->toString(), $installments, $firstDueDate) as $line) {
            $plan->installments()->create($line + ['status' => ClientCreditInstallment::OPEN]);
        }

        return $plan->load('installments');
    }

    public function hasActivePlan(Sale $sale): bool
    {
        return ClientCreditPlan::query()->where('sale_id', $sale->id)->where('status', ClientCreditPlan::ACTIVE)->exists();
    }

    /** Recebimento de crediário estornado (2.3): o principal dele volta às parcelas. */
    public function reverseReceipt(SaleReceipt $receipt): void
    {
        $items = ClientCreditPaymentItem::query()->where('sale_receipt_id', $receipt->id)->whereNull('reversed_at')->with('installment.plan')->get();

        foreach ($items as $item) {
            $installment = $item->installment;
            $installment->update([
                'paid_principal' => Money::of($installment->paid_principal)->minus($item->principal)->toString(),
                'status' => $installment->status === ClientCreditInstallment::CANCELLED ? ClientCreditInstallment::CANCELLED : ClientCreditInstallment::OPEN,
            ]);
            $item->update(['reversed_at' => now()]);

            if ($installment->plan->status === ClientCreditPlan::PAID) {
                $installment->plan->update(['status' => ClientCreditPlan::ACTIVE]);
            }
        }
    }

    /** Venda cancelada: o plano e as parcelas abertas são cancelados (histórico mantido). */
    public function cancelForSale(Sale $sale): void
    {
        $plan = ClientCreditPlan::query()->where('sale_id', $sale->id)->where('status', '!=', ClientCreditPlan::CANCELLED)->first();

        if ($plan === null) {
            return;
        }

        $plan->installments()->where('status', ClientCreditInstallment::OPEN)->update(['status' => ClientCreditInstallment::CANCELLED]);
        $plan->update(['status' => ClientCreditPlan::CANCELLED, 'cancelled_at' => now()]);
    }

    /** @return array{late_fee_percent: string, monthly_interest_percent: string, grace_days: int} */
    public function policyOf(?Organization $organization): array
    {
        if ($organization === null) {
            return self::DEFAULT_POLICY;
        }

        return [
            'late_fee_percent' => (string) ($organization->credit_late_fee_percent ?? self::DEFAULT_POLICY['late_fee_percent']),
            'monthly_interest_percent' => (string) ($organization->credit_monthly_interest_percent ?? self::DEFAULT_POLICY['monthly_interest_percent']),
            'grace_days' => (int) ($organization->credit_grace_days ?? 0),
        ];
    }
}
