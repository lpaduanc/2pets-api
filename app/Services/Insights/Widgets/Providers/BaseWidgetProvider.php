<?php

namespace App\Services\Insights\Widgets\Providers;

use App\Contracts\DashboardWidgetProvider;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Insights\Widgets\DashboardWidgetContext;
use Illuminate\Database\Eloquent\Builder;

/** O que todo provider de widget repete: escopo comercial e parâmetros sem configuração. */
abstract class BaseWidgetProvider implements DashboardWidgetProvider
{
    public function __construct(protected readonly CommercialScopeResolver $scope) {}

    public function parameters(): array
    {
        return [];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function scoped(Builder $query, DashboardWidgetContext $context): Builder
    {
        return $this->scope->scopeQuery($query, $context->user);
    }
}
