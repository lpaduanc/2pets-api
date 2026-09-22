<?php

namespace App\Services\Finance;

use App\Enums\FinancialEntryStatus;
use App\Enums\FinancialNature;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Motor comum de "contas a pagar/a receber" — contrato
 * docs/gap-simplesvet/03-contas-a-pagar-receber-spec.md.
 *
 * NÃO é uma tabela nova: é uma VISÃO sobre `financial_entries` (spec 02) filtrada por
 * `nature`. "Vencido" nunca é uma coluna, é a mesma condição de query que `Invoice::isOverdue()`
 * já usa em produção — derivar em query é a única opção viável sem scheduler (o Docker do
 * projeto não sobe `schedule:work`).
 *
 * `AccountsPayableService`/`AccountsReceivableService` são as fachadas finas que o contrato
 * pede (nomes que o controller e o teste esperam); os 5 totalizadores e a query base moram
 * aqui uma vez só — duas classes quase-idênticas duplicariam a regra.
 */
final class FinancialLedgerSummaryService
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    /**
     * Os 5 totalizadores clicáveis do SimplesVet: `all`, `open` (não pagos), `paid`,
     * `overdue` (vencidos), `due` (a vencer). Mutuamente exclusivos entre si, exceto `all`.
     *
     * @return array<string, array{count: int, total: float}>
     */
    public function summary(User $user, FinancialNature $nature, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = fn (): Builder => $this->baseQuery($user, $nature)
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()]);

        return [
            'all' => $this->totals($base()),
            'open' => $this->totals($base()->openOrPartial()),
            'paid' => $this->totals($base()->where('status', FinancialEntryStatus::PAID->value)),
            'overdue' => $this->totals($this->overdueQuery($user, $nature)),
            'due' => $this->totals($base()->openOrPartial()->whereDate('due_date', '>=', now()->toDateString())),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(User $user, FinancialNature $nature, array $filters): Builder
    {
        $status = $filters['status'] ?? null;

        return $this->baseQuery($user, $nature)
            ->with(['category', 'account', 'supplier', 'paymentMethod'])
            ->when($status === 'overdue', fn (Builder $q) => $q->openOrPartial()->whereDate('due_date', '<', now()->toDateString()))
            ->when($status === 'due', fn (Builder $q) => $q->openOrPartial()->whereDate('due_date', '>=', now()->toDateString()))
            ->when($status !== null && ! in_array($status, ['overdue', 'due'], true), fn (Builder $q) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('due_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('due_date', '<=', $to))
            ->when($filters['payment_method_id'] ?? null, fn (Builder $q, $id) => $q->where('payment_method_id', $id))
            ->when($filters['user_id'] ?? null, fn (Builder $q, $id) => $q->where('created_by', $id))
            ->orderBy('due_date');
    }

    /** Lançamentos pagos no período, pela data de baixa — base do agrupamento por forma. */
    public function paidBetween(User $user, FinancialNature $nature, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $this->baseQuery($user, $nature)
            ->where('status', FinancialEntryStatus::PAID->value)
            ->whereBetween('paid_at', [$from->startOfDay(), $to->endOfDay()]);
    }

    /** Vencido: em aberto (ou parcial) e vencimento no passado — SEMPRE derivado, nunca persistido. */
    private function overdueQuery(User $user, FinancialNature $nature): Builder
    {
        return $this->baseQuery($user, $nature)
            ->openOrPartial()
            ->whereDate('due_date', '<', now()->toDateString());
    }

    private function baseQuery(User $user, FinancialNature $nature): Builder
    {
        return $this->scope->scopeQuery(FinancialEntry::query(), $user)
            ->nature($nature)
            ->where('status', '!=', FinancialEntryStatus::CANCELLED->value);
    }

    /** @return array{count: int, total: float} */
    private function totals(Builder $query): array
    {
        return ['count' => (clone $query)->count(), 'total' => round((float) (clone $query)->sum('net_amount'), 2)];
    }
}
