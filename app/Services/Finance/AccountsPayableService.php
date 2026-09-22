<?php

namespace App\Services\Finance;

use App\Enums\FinancialNature;
use App\Models\FinancialEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Contas a pagar — `financial_entries` com `nature = expense`. Contrato
 * docs/gap-simplesvet/03-contas-a-pagar-receber-spec.md. Regra em `FinancialLedgerSummaryService`.
 */
final class AccountsPayableService
{
    public function __construct(private readonly FinancialLedgerSummaryService $ledger) {}

    /** @return array<string, array{count: int, total: float}> */
    public function summary(User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->ledger->summary($user, FinancialNature::EXPENSE, $from, $to);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<FinancialEntry>
     */
    public function list(User $user, array $filters): Builder
    {
        return $this->ledger->list($user, FinancialNature::EXPENSE, $filters);
    }
}
