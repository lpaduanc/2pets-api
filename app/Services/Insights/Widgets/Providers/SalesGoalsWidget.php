<?php

namespace App\Services\Insights\Widgets\Providers;

use App\Models\Organization;
use App\Models\SalesGoal;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\Goals\SalesGoalProgressService;
use App\Services\Insights\Widgets\DashboardWidgetContext;

/**
 * Meta de vendas (2.5) no layout configurável: o andamento das metas do mês corrente
 * (`SalesGoalProgressService` — realizado = receita líquida, dias úteis, projeção, precisa/dia).
 * Mesmo dado e mesma permissão de `GET sales-goals/progress` (`reports.dre.view`); o cadastro
 * continua em `sales-goals`.
 */
final class SalesGoalsWidget extends BaseWidgetProvider
{
    public function __construct(CommercialScopeResolver $scope, private readonly SalesGoalProgressService $progress)
    {
        parent::__construct($scope);
    }

    public function key(): string
    {
        return 'sales_goals';
    }

    public function label(): string
    {
        return 'Metas de venda';
    }

    public function description(): string
    {
        return 'Andamento das metas do mês: realizado, projeção e quanto falta por dia.';
    }

    public function domain(): string
    {
        return 'sales';
    }

    public function permission(): string
    {
        return 'reports.dre.view';
    }

    public function data(DashboardWidgetContext $context): array
    {
        $month = $context->today->format('Y-m');
        $goals = $this->scoped(SalesGoal::query(), $context)->where('month', $month)->orderBy('scope')->orderBy('id')->get();

        return $this->progress->progress($context->user, $context->organization instanceof Organization ? $context->organization : null, $month, $goals);
    }
}
