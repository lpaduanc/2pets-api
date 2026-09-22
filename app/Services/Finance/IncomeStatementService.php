<?php

namespace App\Services\Finance;

use App\Enums\FinancialEntryStatus;
use App\Enums\FinancialNature;
use App\Models\FinancialCategory;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * DRE (demonstração de resultado) — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * `accrual_date` (competência) e `paid_at` (caixa) são a MESMA linha em `financial_entries`,
 * duas datas: o regime escolhido troca qual coluna agrupa por mês, sem duplicar dado. Regime de
 * caixa só enxerga lançamento já baixado (`paid_at` não nulo) — é dinheiro que efetivamente
 * entrou/saiu, por definição.
 */
final class IncomeStatementService
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(User $user, string $regime, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = $this->monthKeys($from, $to);
        $rows = $this->rowsByCategory($this->entriesFor($user, $regime, $from, $to), $regime, $months);
        $rows = $this->withGroupRollups($rows, $months);

        return [
            'regime' => $regime,
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'months' => $months,
            'categories' => $rows->values()->all(),
            'totals' => $this->totalsByNature($rows, $months),
        ];
    }

    /**
     * @return Collection<int, FinancialEntry>
     */
    private function entriesFor(User $user, string $regime, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $column = $regime === 'accrual' ? 'accrual_date' : 'paid_at';

        return $this->scope->scopeQuery(FinancialEntry::query(), $user)
            ->where('status', '!=', FinancialEntryStatus::CANCELLED->value)
            ->when($regime === 'cash', fn (Builder $q) => $q->whereNotNull('paid_at'))
            ->whereBetween($column, [$from->startOfDay(), $to->endOfDay()])
            ->with('category')
            ->get();
    }

    /** @return list<string> */
    private function monthKeys(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        $cursor = $from->startOfMonth();

        while ($cursor->lessThanOrEqualTo($to)) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    /**
     * @param  Collection<int, FinancialEntry>  $entries
     * @param  list<string>  $months
     * @return Collection<int, array<string, mixed>>
     */
    private function rowsByCategory(Collection $entries, string $regime, array $months): Collection
    {
        return $entries->groupBy('financial_category_id')->map(
            fn (Collection $group): array => $this->categoryRow($group, $regime, $months)
        );
    }

    /**
     * @param  Collection<int, FinancialEntry>  $group
     * @param  list<string>  $months
     * @return array<string, mixed>
     */
    private function categoryRow(Collection $group, string $regime, array $months): array
    {
        $category = $group->first()->category;
        $byMonth = array_fill_keys($months, 0.0);

        foreach ($group as $entry) {
            $date = $regime === 'accrual' ? $entry->accrual_date : $entry->paid_at;
            $key = $date->format('Y-m');
            $amount = $regime === 'cash' ? (float) $entry->paid_amount : (float) $entry->net_amount;

            if (array_key_exists($key, $byMonth)) {
                $byMonth[$key] = round($byMonth[$key] + $amount, 2);
            }
        }

        return [
            'category_id' => $category?->id,
            'category_name' => $category?->name ?? 'Sem categoria',
            'parent_id' => $category?->parent_id,
            'nature' => $category?->nature->value,
            'months' => $byMonth,
            'total' => round(array_sum($byMonth), 2),
            'is_group' => false,
        ];
    }

    /**
     * Categoria-pai apresenta o total das filhas (soma recursiva) — critério de aceite do doc
     * 02. Uma linha sintética por grupo que tem alguma folha com lançamento no período.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $months
     * @return Collection<int, array<string, mixed>>
     */
    private function withGroupRollups(Collection $rows, array $months): Collection
    {
        $byParent = $rows->filter(fn (array $row): bool => $row['parent_id'] !== null)->groupBy('parent_id');

        if ($byParent->isEmpty()) {
            return $rows;
        }

        $parents = FinancialCategory::whereIn('id', $byParent->keys())->get()->keyBy('id');

        return $rows->concat($byParent->map(
            fn (Collection $children, int $parentId): array => $this->groupRow($parents->get($parentId), $parentId, $children, $months)
        )->values());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $children
     * @param  list<string>  $months
     * @return array<string, mixed>
     */
    private function groupRow(?FinancialCategory $parent, int $parentId, Collection $children, array $months): array
    {
        $byMonth = array_fill_keys($months, 0.0);

        foreach ($children as $child) {
            foreach ($child['months'] as $month => $amount) {
                $byMonth[$month] = round($byMonth[$month] + $amount, 2);
            }
        }

        return [
            'category_id' => $parentId,
            'category_name' => $parent?->name ?? 'Grupo',
            'parent_id' => null,
            'nature' => $parent?->nature->value,
            'months' => $byMonth,
            'total' => round(array_sum($byMonth), 2),
            'is_group' => true,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $months
     * @return array<string, array<string, float>>
     */
    private function totalsByNature(Collection $rows, array $months): array
    {
        $totals = [
            FinancialNature::REVENUE->value => array_fill_keys($months, 0.0),
            FinancialNature::EXPENSE->value => array_fill_keys($months, 0.0),
        ];

        foreach ($rows as $row) {
            $nature = $row['nature'] ?? FinancialNature::EXPENSE->value;

            foreach ($row['months'] as $month => $amount) {
                $totals[$nature][$month] = round($totals[$nature][$month] + $amount, 2);
            }
        }

        $net = [];
        foreach ($months as $month) {
            $net[$month] = round($totals[FinancialNature::REVENUE->value][$month] - $totals[FinancialNature::EXPENSE->value][$month], 2);
        }

        return ['revenue' => $totals[FinancialNature::REVENUE->value], 'expense' => $totals[FinancialNature::EXPENSE->value], 'net' => $net];
    }
}
