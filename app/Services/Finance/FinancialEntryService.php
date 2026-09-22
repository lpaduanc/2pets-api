<?php

namespace App\Services\Finance;

use App\DataTransferObjects\Finance\PlannedFinancialInstallment;
use App\Enums\FinancialCategoryKind;
use App\Enums\FinancialEntryStatus;
use App\Enums\FinancialNature;
use App\Models\FinancialAccount;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lançamento manual (receita/despesa), parcelamento, baixa e estorno da baixa — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * Ponto de extensão documentado para o item 09 (comissionamento interno): fechar uma comissão
 * pode lançar despesa aqui com `reference_type = commission_settlement` via `create()` — não
 * duplique a lógica de parcelamento/baixa numa classe própria.
 */
final class FinancialEntryService
{
    private const RELATIONS = ['category', 'account', 'supplier', 'paymentMethod'];

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly FinancialInstallmentPlanner $planner,
        private readonly FinancialEntryCashMirror $cashMirror,
        private readonly FinancialEntrySeriesEditor $seriesEditor,
        private readonly FinancialEntrySettlementCalculator $settlementCalculator,
    ) {}

    /**
     * Cria 1 lançamento avulso ou N parcelas com o mesmo `series_id`. Toda parcela nasce
     * `open`: baixa é ato separado (`settle()`).
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, FinancialEntry>
     */
    public function create(User $user, array $data): Collection
    {
        $category = $this->scope->scopeQuery(FinancialCategory::query(), $user)
            ->findOrFail((int) $data['financial_category_id']);
        $this->assertEntryCategory($category, FinancialNature::from($data['nature']));

        $resolved = $data;
        $resolved['account_id'] = $this->resolveAccountId($user, $data['account_id'] ?? null);
        $ownership = $this->scope->ownershipFor($user);

        $plan = $this->planner->plan(
            (float) $data['amount'],
            max(1, (int) ($data['installments'] ?? 1)),
            CarbonImmutable::parse($data['due_date']),
            (int) ($data['interval_days'] ?? 30),
            $ownership['organization_id'],
        );

        return DB::transaction(fn (): Collection => $plan
            ->map(fn (PlannedFinancialInstallment $installment): FinancialEntry => $this->store($ownership, $category, $resolved, $installment))
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FinancialEntry $entry, User $user, array $data): FinancialEntry
    {
        abort_if($entry->status !== FinancialEntryStatus::OPEN, 422, 'Só lançamento em aberto pode ser editado diretamente.');

        if (isset($data['financial_category_id'])) {
            $category = $this->scope->scopeQuery(FinancialCategory::query(), $user)->findOrFail((int) $data['financial_category_id']);
            $this->assertEntryCategory($category, $entry->nature);
        }

        $entry->update(collect($data)->only([
            'financial_category_id', 'supplier_id', 'payment_method_id', 'description', 'due_date', 'accrual_date', 'notes',
        ])->all());

        if (isset($data['amount'])) {
            $entry->update(['amount' => round((float) $data['amount'], 2), 'net_amount' => $this->netAmountOf($entry, (float) $data['amount'])]);
        }

        return $entry->fresh(self::RELATIONS);
    }

    /**
     * Baixa reversível. Aceita baixa parcial (`paid_amount < net_amount`).
     *
     * @param  array<string, mixed>  $data
     */
    public function settle(FinancialEntry $entry, User $user, array $data): FinancialEntry
    {
        abort_if($entry->status === FinancialEntryStatus::CANCELLED, 422, 'Lançamento cancelado não recebe baixa.');
        abort_if($entry->status === FinancialEntryStatus::PAID, 422, 'Lançamento já está totalmente pago.');

        $settlement = $this->settlementCalculator->calculate($entry, $data, $this->resolveAccountId($user, $data['account_id'] ?? $entry->account_id));

        return DB::transaction(function () use ($entry, $user, $settlement): FinancialEntry {
            $entry->update($settlement);
            $this->cashMirror->mirrorExpense($entry->fresh(), $user, (float) $settlement['paid_amount'], $settlement['paid_at']);

            return $entry->fresh(self::RELATIONS);
        });
    }

    public function unsettle(FinancialEntry $entry): FinancialEntry
    {
        abort_if($entry->status === FinancialEntryStatus::CANCELLED, 422, 'Lançamento cancelado não tem baixa para estornar.');
        $entry->update(['status' => FinancialEntryStatus::OPEN, 'paid_at' => null, 'paid_amount' => null]);

        return $entry->fresh(self::RELATIONS);
    }

    public function cancel(FinancialEntry $entry): FinancialEntry
    {
        abort_if($entry->status === FinancialEntryStatus::PAID, 422, 'Lançamento pago não pode ser cancelado; estorne a baixa primeiro.');
        $entry->update(['status' => FinancialEntryStatus::CANCELLED]);

        return $entry;
    }

    /**
     * "Editar a parcela 3 com replicar para as próximas" — delega a `FinancialEntrySeriesEditor`
     * (concern próprio, ver a classe para a regra completa).
     *
     * @param  array<string, mixed>  $changes
     * @return Collection<int, FinancialEntry>
     */
    public function updateSeriesFrom(string $seriesId, int $fromInstallment, User $user, array $changes): Collection
    {
        return $this->seriesEditor->updateFrom($seriesId, $fromInstallment, $user, $changes);
    }

    /**
     * @param  array{organization_id: int|null, professional_id: int}  $ownership
     * @param  array<string, mixed>  $data  já com `account_id` resolvido no escopo
     */
    private function store(array $ownership, FinancialCategory $category, array $data, PlannedFinancialInstallment $installment): FinancialEntry
    {
        return FinancialEntry::create([
            'financial_category_id' => $category->id,
            'account_id' => $data['account_id'],
            'supplier_id' => $data['supplier_id'] ?? null,
            'payment_method_id' => $data['payment_method_id'] ?? null,
            'description' => $data['description'],
            'nature' => $data['nature'],
            'due_date' => $installment->dueDate->toDateString(),
            'accrual_date' => $data['accrual_date'] ?? $installment->dueDate->toDateString(),
            'amount' => round($installment->amount, 2),
            'net_amount' => round($installment->amount, 2),
            'status' => FinancialEntryStatus::OPEN,
            'series_id' => $installment->seriesId,
            'installment_number' => $installment->seriesId === null ? null : $installment->number,
            'installment_total' => $installment->seriesId === null ? null : $installment->total,
            'reference_type' => 'manual',
            'notes' => $data['notes'] ?? null,
            'created_by' => $ownership['professional_id'],
        ] + $ownership);
    }

    private function netAmountOf(FinancialEntry $entry, float $amount): float
    {
        return round($amount - (float) $entry->discount + (float) $entry->fine + (float) $entry->interest, 2);
    }

    private function assertEntryCategory(FinancialCategory $category, FinancialNature $nature): void
    {
        abort_if($category->kind === FinancialCategoryKind::GROUP, 422, 'Categoria de grupo não recebe lançamento; escolha uma categoria-folha.');
        abort_if($category->nature !== $nature, 422, 'A natureza do lançamento não corresponde à categoria escolhida.');
    }

    private function resolveAccountId(User $user, mixed $accountId): ?int
    {
        if ($accountId === null) {
            return null;
        }

        return $this->scope->scopeQuery(FinancialAccount::query(), $user)->findOrFail((int) $accountId)->getKey();
    }
}
