<?php

namespace App\Services\Finance;

use App\Enums\FinancialNature;
use App\Models\FinancialAccount;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Services\Commercial\AccountBalanceService;
use App\Services\Commercial\CommercialScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Fluxo de caixa projetado — contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * Projeta o futuro a partir de `due_date` de lançamentos EM ABERTO, calculado em query — sem
 * job nem scheduler (o Docker do projeto não sobe `schedule:work`). Ponto de partida é o saldo
 * REAL de hoje (`AccountBalanceService`), não um valor arbitrário.
 */
final class CashFlowProjectionService
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly AccountBalanceService $balances,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function project(User $user, int $months = 12): array
    {
        $today = CarbonImmutable::today();
        $openingBalance = $this->currentBalance($user);
        $openEntries = $this->openEntries($user, $today, $months);

        $running = $openingBalance;
        $buckets = collect(range(0, $months - 1))->map(function (int $offset) use ($today, $openEntries, &$running): array {
            $month = $today->addMonthsNoOverflow($offset)->format('Y-m');
            $bucket = $this->bucketFor($openEntries, $month);
            $running = round($running + $bucket['expected_revenue'] - $bucket['expected_expense'], 2);

            return $bucket + ['month' => $month, 'projected_balance' => $running];
        });

        return ['opening_balance' => round($openingBalance, 2), 'months' => $buckets->all()];
    }

    private function currentBalance(User $user): float
    {
        $accounts = $this->scope->scopeQuery(FinancialAccount::query(), $user)->active()->get();

        return round(array_sum($this->balances->balancesFor($accounts)), 2);
    }

    /**
     * @return Collection<int, FinancialEntry>
     */
    private function openEntries(User $user, CarbonImmutable $today, int $months): Collection
    {
        return $this->scope->scopeQuery(FinancialEntry::query(), $user)
            ->openOrPartial()
            ->whereBetween('due_date', [$today->toDateString(), $today->addMonthsNoOverflow($months)->toDateString()])
            ->get();
    }

    /**
     * @param  Collection<int, FinancialEntry>  $entries
     * @return array{expected_revenue: float, expected_expense: float, net: float}
     */
    private function bucketFor(Collection $entries, string $month): array
    {
        $ofMonth = $entries->filter(fn (FinancialEntry $entry): bool => $entry->due_date->format('Y-m') === $month);

        $revenue = round((float) $ofMonth->where('nature', FinancialNature::REVENUE)->sum($this->outstanding()), 2);
        $expense = round((float) $ofMonth->where('nature', FinancialNature::EXPENSE)->sum($this->outstanding()), 2);

        return ['expected_revenue' => $revenue, 'expected_expense' => $expense, 'net' => round($revenue - $expense, 2)];
    }

    /** Saldo que falta baixar da parcela — baixa parcial só entra pelo que resta. */
    private function outstanding(): \Closure
    {
        return fn (FinancialEntry $entry): float => (float) $entry->net_amount - (float) ($entry->paid_amount ?? 0);
    }
}
