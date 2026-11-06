<?php

namespace App\Services\Commercial\Goals;

use App\Enums\SalesGoalScope;
use App\Models\CostCenter;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\SalesGoal;
use App\Models\User;
use App\Services\Finance\Managerial\ManagerialNetRevenue;
use App\Services\Finance\Managerial\ManagerialSalesDimension;
use App\Support\Money\Money;
use Illuminate\Support\Collection;

/**
 * Andamento das metas do mês (PLANO 2.5): realizado pela receita líquida da DRE gerencial
 * (`ManagerialNetRevenue`, definição única), dias úteis pelo `BusinessDayCalendar` e a conta pelo
 * `SalesGoalCalculator`. Por escopo: total; produtos/serviços pelo tipo do item (sessão de pacote
 * é serviço); centro de custo pelo centro efetivo (2.4); profissional pelo `staff_id` do item
 * (na sessão de pacote, quem executou).
 */
final class SalesGoalProgressService
{
    public function __construct(
        private readonly ManagerialNetRevenue $revenue,
        private readonly BusinessDayCalendar $calendar,
        private readonly SalesGoalCalculator $calculator,
    ) {}

    /**
     * @param  Collection<int, SalesGoal>  $goals
     * @return array{month: string, business_days: array{total: int, elapsed: int, remaining: int}, goals: list<array<string, mixed>>}
     */
    public function progress(User $user, ?Organization $organization, string $month, Collection $goals): array
    {
        $days = $this->calendar->forMonth($organization, $month);
        $byDimension = [];
        $actualOf = function (SalesGoal $goal) use ($user, $month, &$byDimension): Money {
            [$dimension, $key] = match ($goal->scope) {
                SalesGoalScope::TOTAL => [ManagerialSalesDimension::ITEM_TYPE, null],
                SalesGoalScope::PRODUCTS => [ManagerialSalesDimension::ITEM_TYPE, ManagerialSalesDimension::PRODUCTS],
                SalesGoalScope::SERVICES => [ManagerialSalesDimension::ITEM_TYPE, ManagerialSalesDimension::SERVICES],
                SalesGoalScope::COST_CENTER => [ManagerialSalesDimension::COST_CENTER, (string) $goal->scope_id],
                SalesGoalScope::STAFF => [ManagerialSalesDimension::STAFF, (string) $goal->scope_id],
            };
            $values = $byDimension[$dimension->value] ??= $this->revenue->forMonth($user, $month, $dimension);

            return $key === null ? Money::sum($values) : ($values[$key] ?? Money::zero());
        };
        $names = $this->scopeNames($goals);

        return [
            'month' => $month,
            'business_days' => $days,
            'goals' => $goals->map(fn (SalesGoal $goal): array => [
                'id' => $goal->id,
                'scope' => $goal->scope->value,
                'scope_label' => $goal->scope->label(),
                'scope_id' => $goal->scope_id,
                'scope_name' => $names[$goal->scope->value][$goal->scope_id] ?? null,
                'target' => (string) $goal->amount,
                'actual' => ($actual = $actualOf($goal))->toString(),
            ] + $this->calculator->progress((string) $goal->amount, $actual->toString(), $days['total'], $days['elapsed'], $days['remaining']))->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, SalesGoal>  $goals
     * @return array<string, array<int, string>>
     */
    private function scopeNames(Collection $goals): array
    {
        $ids = fn (SalesGoalScope $scope): array => $goals->where('scope', $scope)->pluck('scope_id')->all();

        return [
            SalesGoalScope::COST_CENTER->value => CostCenter::withTrashed()->whereIn('id', $ids(SalesGoalScope::COST_CENTER))->pluck('name', 'id')->all(),
            SalesGoalScope::STAFF->value => OrganizationMember::query()->whereIn('organization_members.id', $ids(SalesGoalScope::STAFF))
                ->join('users', 'users.id', '=', 'organization_members.user_id')->pluck('users.name', 'organization_members.id')->all(),
        ];
    }
}
