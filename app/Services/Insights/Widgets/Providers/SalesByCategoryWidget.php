<?php

namespace App\Services\Insights\Widgets\Providers;

use App\Models\ProductGroup;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Finance\Managerial\ManagerialNetRevenue;
use App\Services\Finance\Managerial\ManagerialSalesDimension;
use App\Services\Insights\Widgets\DashboardWidgetContext;
use App\Services\Insights\Widgets\WidgetMath;
use App\Support\Money\Money;

/**
 * Vendas por categoria: receita líquida dos últimos N dias (janela inclusiva, N = `days`,
 * padrão 30) por grupo de produto/serviço, produtos e serviços separados. Receita líquida é a
 * definição única da 2.5 (`ManagerialNetRevenue`): vendas pagas, menos desconto rateado e
 * devolução, mais sessão de pacote no dia da baixa (serviço). Grupo vazio = "Sem grupo".
 * Valores de venda: `reports.dre.view`, como as metas.
 */
final class SalesByCategoryWidget extends BaseWidgetProvider
{
    public function __construct(CommercialScopeResolver $scope, private readonly ManagerialNetRevenue $revenue)
    {
        parent::__construct($scope);
    }

    public function key(): string
    {
        return 'sales_by_category';
    }

    public function label(): string
    {
        return 'Vendas por categoria';
    }

    public function description(): string
    {
        return 'Receita líquida por grupo, produtos e serviços separados.';
    }

    public function domain(): string
    {
        return 'sales';
    }

    public function permission(): string
    {
        return 'reports.dre.view';
    }

    public function parameters(): array
    {
        return ['days' => ['type' => 'int', 'default' => 30, 'min' => 1, 'max' => 365]];
    }

    public function data(DashboardWidgetContext $context): array
    {
        [$from, $to] = WidgetMath::window($context->today, $context->int('days'));
        $byKey = $this->revenue->forPeriod($context->user, $from, $to, ManagerialSalesDimension::TYPE_AND_GROUP);
        $groupIds = collect(array_keys($byKey))->map(fn (string $key): string => explode(':', $key, 2)[1] ?? '')->filter()->unique()->values()->all();
        $names = ProductGroup::query()->whereIn('id', $groupIds)->pluck('name', 'id');
        $section = function (string $type) use ($byKey, $names): array {
            $rows = collect($byKey)->filter(fn (Money $value, string $key): bool => str_starts_with($key, $type.':'))
                ->map(function (Money $value, string $key) use ($names): array {
                    $groupId = explode(':', $key, 2)[1] ?? '';

                    return ['group_id' => $groupId === '' ? null : (int) $groupId, 'group_name' => $groupId === '' ? 'Sem grupo' : ($names[(int) $groupId] ?? 'Grupo removido'), 'net_revenue' => $value->toString()];
                })->values()->all();

            return ['total' => Money::sum(array_column($rows, 'net_revenue'))->toString(), 'groups' => WidgetMath::top($rows, 'net_revenue', 'group_name', PHP_INT_MAX)];
        };

        return ['days' => $context->int('days'), 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'products' => $section('products'), 'services' => $section('services')];
    }
}
