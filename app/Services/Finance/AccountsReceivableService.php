<?php

namespace App\Services\Finance;

use App\Enums\FinancialNature;
use App\Models\FinancialEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Contas a receber — `financial_entries` com `nature = revenue` (já inclui venda de balcão e
 * fatura de atendimento, pela integração da spec 02). Contrato
 * docs/gap-simplesvet/03-contas-a-pagar-receber-spec.md. Regra em `FinancialLedgerSummaryService`.
 */
final class AccountsReceivableService
{
    public function __construct(private readonly FinancialLedgerSummaryService $ledger) {}

    /** @return array<string, array{count: int, total: float}> */
    public function summary(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->ledger->summary($user, FinancialNature::REVENUE, $from, $to);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<FinancialEntry>
     */
    public function list(User $user, array $filters): Builder
    {
        return $this->ledger->list($user, FinancialNature::REVENUE, $filters);
    }

    /**
     * Recebido no período, agrupado por forma de recebimento — mesmo formato de
     * `FinancialOverviewService::receivedByPaymentMethod()` (contrato do doc 01), estendido
     * para os lançamentos automáticos de venda/fatura que já caem em `financial_entries`.
     *
     * @return list<array{label: string, amount: float}>
     */
    public function receivedByPaymentMethod(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $totals = [];

        foreach ($this->ledger->paidBetween($user, FinancialNature::REVENUE, $from, $to)->with('paymentMethod')->get() as $entry) {
            $label = $entry->paymentMethod?->name ?? 'Outros';
            $totals[$label] = ($totals[$label] ?? 0) + (float) $entry->net_amount;
        }

        arsort($totals);

        return array_map(
            fn (string $label, float $amount): array => ['label' => $label, 'amount' => round($amount, 2)],
            array_keys($totals),
            array_values($totals),
        );
    }
}
