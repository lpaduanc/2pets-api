<?php

namespace App\Services\Insights;

use App\DataTransferObjects\InsightQuery;
use App\Enums\InsightDimension;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;

/**
 * `GET insights/productivity` e `GET me/productivity` — ranking por colaborador, contrato
 * docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md.
 *
 * É a série de `SalesInsightsService` com `dimension=employee` forçada e o nome de cada
 * vínculo anexado (`CommercialScopeResolver::teamMembers()`, já eager-carrega `user:id,name` —
 * reaproveitado em vez de uma segunda query própria).
 */
final class ProductivityService
{
    public function __construct(
        private readonly SalesInsightsService $insights,
        private readonly ProductivityAuthorization $authorization,
        private readonly CommercialScopeResolver $scope,
    ) {}

    /**
     * @param  list<int>  $requestedEmployeeIds
     * @return array{series: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function ranking(User $user, InsightQuery $query, array $requestedEmployeeIds): array
    {
        $employeeIds = $this->authorization->resolveEmployeeIds($user, $requestedEmployeeIds);

        return $this->withEmployeeNames($user, $this->employeeSeries($user, $query, $employeeIds));
    }

    /**
     * @return array{series: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function myProductivity(User $user, InsightQuery $query): array
    {
        $ownMembershipIds = $this->authorization->ownMembershipIds($user);

        return $this->withEmployeeNames($user, $this->employeeSeries($user, $query, $ownMembershipIds));
    }

    /**
     * @param  list<int>  $employeeIds
     * @return array{series: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function employeeSeries(User $user, InsightQuery $query, array $employeeIds): array
    {
        $employeeQuery = $query->withDimension(InsightDimension::EMPLOYEE)->withEmployeeIds($employeeIds);

        return $this->insights->series($user, $employeeQuery);
    }

    /**
     * @param  array{series: list<array<string, mixed>>, totals: array<string, mixed>}  $result
     * @return array{series: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function withEmployeeNames(User $user, array $result): array
    {
        $names = $this->scope->teamMembers($user)->pluck('user.name', 'id');

        $result['series'] = collect($result['series'])
            ->map(fn (array $point): array => $point + ['employee_name' => $names->get($point['bucket'])])
            ->all();

        return $result;
    }
}
