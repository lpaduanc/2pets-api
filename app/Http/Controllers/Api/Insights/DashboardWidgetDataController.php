<?php

namespace App\Http\Controllers\Api\Insights;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Insights\Widgets\DashboardWidgetContext;
use App\Services\Insights\Widgets\DashboardWidgetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Widgets do painel com provider (PLANO 3.6), no mesmo mecanismo de `dashboard-widgets`:
 *  - `GET dashboard-widgets/catalog`: os providers que o usuário pode usar (pela permissão de
 *    cada um), mais o indicador de BI `sales` de sempre;
 *  - `GET dashboard-widgets/data/{key}`: os dados de um widget, com os parâmetros na query
 *    (os mesmos do `config` salvo). Sem permissão: 403; chave desconhecida: 404.
 */
class DashboardWidgetDataController extends Controller
{
    public function __construct(
        private readonly DashboardWidgetRegistry $registry,
        private readonly CommercialScopeResolver $scope,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        return response()->json(['data' => array_map(
            fn ($provider): array => $this->registry->describe($provider),
            $this->registry->availableFor($request->user()),
        )]);
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $provider = $this->registry->find($key);
        abort_if($provider === null, 404, 'Widget não encontrado.');
        abort_unless($this->registry->allows($request->user(), $provider), 403, 'Você não tem permissão para ver este widget.');

        $organizationId = $this->scope->primaryOrganizationId($request->user());
        $context = new DashboardWidgetContext(
            $request->user(),
            $organizationId === null ? null : Organization::find($organizationId),
            \App\Support\BusinessClock::today(),
            $this->registry->resolveParams($provider, $request->query()),
        );

        return response()->json(['data' => [
            'key' => $provider->key(),
            'params' => $context->params,
            'generated_at' => now()->toIso8601String(),
            'values' => $provider->data($context),
        ]]);
    }
}
