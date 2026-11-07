<?php

namespace App\Contracts;

use App\Services\Insights\Widgets\DashboardWidgetContext;

/**
 * Um widget do painel (PLANO 3.6). Cada provider declara, explicitamente, a fórmula (no docblock
 * da classe), a permissão que exige (julgada pelo cargo em TODA organização do escopo,
 * `SalePermissionGate::allowsAcrossScope`, como a DRE gerencial) e os parâmetros que aceita. Os
 * dados são sempre do escopo comercial de quem pede (`CommercialScopeResolver`).
 *
 * Valores de dinheiro saem como string com 2 casas; percentuais como string ou `null`.
 */
interface DashboardWidgetProvider
{
    /** Chave estável, gravada em `dashboard_widgets.indicator_key`. */
    public function key(): string;

    public function label(): string;

    public function description(): string;

    /** Domínio para o front agrupar o catálogo: stock · sales · agenda · clients · finance · packages · profit. */
    public function domain(): string;

    public function permission(): string;

    /**
     * Parâmetros aceitos (`config` do widget), com default: `['days' => ['type' => 'int', 'default' => 30, 'min' => 1, 'max' => 365]]`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function parameters(): array;

    /** @return array<string, mixed> */
    public function data(DashboardWidgetContext $context): array;
}
