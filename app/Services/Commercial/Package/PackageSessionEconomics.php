<?php

namespace App\Services\Commercial\Package;

use App\Enums\AppointmentStatus;
use App\Enums\CommissionTrigger;
use App\Enums\SaleKind;
use App\Enums\SaleStatus;
use App\Enums\SoldPackageSessionStatus;
use App\Enums\SoldPackageStatus;
use App\Models\CommissionRule;
use App\Models\SaleItem;
use App\Models\Service;
use App\Models\SoldPackage;
use App\Models\SoldPackageSession;
use App\Services\Commercial\CommissionCalculator;
use App\Services\Commercial\CommissionRuleResolver;
use App\Services\Commercial\Profitability\SaleAllocationService;
use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * O que uma sessão de pacote vale para comissão, rentabilidade e DRE (PLANO 2.7).
 *
 * Partes da linha do pacote: a taxa da adquirente absorvida e o imposto estimado da LINHA do
 * pacote na venda são rateados entre TODAS as sessões dele pelo valor de cada uma (maior resto),
 * então Σ sessões = valor da linha, centavo a centavo.
 *
 * Comissão da sessão: regra resolvida como se a sessão fosse um item do SERVIÇO consumido (o
 * escopo de serviço/grupo vale), para quem executou (`staff_id`). Piso comum da 2.6: a venda do
 * pacote paga com recebimento valendo e o pacote não cancelado. Data em que nasce, pelo gatilho:
 *  - on_execution: a conclusão do agendamento da baixa; baixa sem agendamento cai em on_sale;
 *  - on_sale: a baixa (a sessão é "vendida" quando é usada; o pacote foi vendido antes);
 *  - on_receipt: a mais tardia entre a baixa e o último recebimento da venda do pacote.
 */
final class PackageSessionEconomics
{
    public function __construct(
        private readonly SaleAllocationService $allocation,
        private readonly CommissionRuleResolver $resolver,
        private readonly CommissionCalculator $calculator,
    ) {}

    /**
     * Exige `soldPackage.sale.items`, `.receipts`, `.organization` e `soldPackage.sessions`.
     *
     * @return array<int, array{card_fee: Money, tax: Money}> por sessão
     */
    public function lineShares(SoldPackage $package): array
    {
        $sale = $package->sale;
        $weights = $package->sessions->mapWithKeys(fn (SoldPackageSession $session): array => [$session->id => Money::of($session->amount)])->all();

        if ($weights === []) {
            return [];
        }

        $lineNet = $this->allocation->netItemTotals($sale)[$package->sale_item_id] ?? Money::zero();
        $fee = $this->allocation->cardFeeShares($sale)[$package->sale_item_id] ?? Money::zero();
        $rate = $sale->organization?->effective_sales_tax_rate;
        $tax = $rate === null ? Money::zero() : $lineNet->percentage(Decimal::of($rate));
        $fees = $fee->allocate($weights);
        $taxes = $tax->allocate($weights);

        return array_map(fn (int $id): array => ['card_fee' => $fees[$id], 'tax' => $taxes[$id]], array_combine(array_keys($weights), array_keys($weights)));
    }

    /** Regra de comissão da sessão, resolvida como item do serviço consumido. */
    public function rule(SoldPackageSession $session): ?CommissionRule
    {
        return $this->resolver->resolve($this->asServiceItem($session));
    }

    public function effectiveTrigger(SoldPackageSession $session, ?CommissionRule $rule): CommissionTrigger
    {
        $trigger = $rule?->trigger ?? CommissionTrigger::ON_RECEIPT;

        if ($trigger === CommissionTrigger::ON_EXECUTION && $session->consumption?->appointment_id === null) {
            return CommissionTrigger::ON_SALE;
        }

        return $trigger;
    }

    /** Data em que a comissão da sessão nasceu; `null` enquanto não nasceu. */
    public function bornAt(SoldPackageSession $session, CommissionTrigger $trigger): ?CarbonImmutable
    {
        $sale = $session->soldPackage->sale;
        $lastReceipt = $sale->receipts->whereNull('reversed_at')->max('received_at');

        if (! $this->isCommissionable($session) || $lastReceipt === null || $session->consumed_at === null) {
            return null;
        }

        $consumedAt = CarbonImmutable::instance($session->consumed_at);

        return match ($trigger) {
            CommissionTrigger::ON_SALE => $consumedAt,
            CommissionTrigger::ON_RECEIPT => $consumedAt->max(CarbonImmutable::instance($lastReceipt)),
            CommissionTrigger::ON_EXECUTION => $this->executedAt($session),
        };
    }

    /** @return array{base_amount: float, commission_amount: float} */
    public function commission(SoldPackageSession $session, ?CommissionRule $rule, Money $cardFeeShare): array
    {
        $fallback = $session->soldPackage->saleItem?->commission_percent;

        return $this->calculator->calculateForSession($session, $rule, $cardFeeShare, $fallback === null ? null : (string) $fallback);
    }

    /**
     * Comissão que a sessão carrega na rentabilidade: a do fechamento, senão a calculada — só
     * para sessão usada, com executor; `on_execution` com agendamento cancelado não projeta nada.
     */
    public function commissionForProfitability(SoldPackageSession $session, Money $cardFeeShare): Money
    {
        if ($session->commissionSettlementItem !== null) {
            return Money::of($session->commissionSettlementItem->commission_amount);
        }

        if ($session->status !== SoldPackageSessionStatus::CONSUMED || $session->staff_id === null) {
            return Money::zero();
        }

        $rule = $this->rule($session);
        $status = $session->consumption?->appointment?->status;

        if ($this->effectiveTrigger($session, $rule) === CommissionTrigger::ON_EXECUTION
            && in_array($status, [null, AppointmentStatus::CANCELLED->value, AppointmentStatus::NO_SHOW->value], true)) {
            return Money::zero();
        }

        return Money::of($this->commission($session, $rule, $cardFeeShare)['commission_amount']);
    }

    private function isCommissionable(SoldPackageSession $session): bool
    {
        $sale = $session->soldPackage->sale;

        return $session->status === SoldPackageSessionStatus::CONSUMED
            && $session->soldPackage->status !== SoldPackageStatus::CANCELLED
            && $sale->status === SaleStatus::PAID
            && $sale->kind === SaleKind::SALE;
    }

    private function executedAt(SoldPackageSession $session): ?CarbonImmutable
    {
        $appointment = $session->consumption?->appointment;

        if ($appointment === null || $appointment->status !== AppointmentStatus::COMPLETED->value) {
            return null;
        }

        return CarbonImmutable::instance($appointment->completed_at ?? $appointment->updated_at);
    }

    /** Item de venda em memória (nunca gravado) só para o `CommissionRuleResolver`. */
    private function asServiceItem(SoldPackageSession $session): SaleItem
    {
        $service = $session->soldPackageItem->service;
        $item = (new SaleItem)->forceFill([
            'sellable_type' => Service::class,
            'sellable_id' => $service?->id,
            'staff_id' => $session->staff_id,
        ]);
        $item->setRelation('sellable', $service);
        $item->setRelation('sale', $session->soldPackage->sale);

        return $item;
    }
}
