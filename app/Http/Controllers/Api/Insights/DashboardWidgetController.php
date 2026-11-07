<?php

namespace App\Http\Controllers\Api\Insights;

use App\Http\Controllers\Controller;
use App\Http\Requests\Insights\StoreDashboardWidgetRequest;
use App\Models\DashboardWidget;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Insights\Widgets\DashboardWidgetRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `dashboard-widgets` (index/store/destroy) — painel de controle pessoal, contrato
 * docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md. Widget é sempre PESSOAL
 * (`user_id`), nunca compartilhado entre a equipe — cada um monta o próprio painel.
 */
class DashboardWidgetController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly DashboardWidgetRegistry $registry,
    ) {}

    /**
     * PLANO 3.6: cada widget diz se é de provider (`kind = provider`) e se o usuário ainda pode
     * vê-lo (`available`) — cargo mudou, o widget salvo fica, mas o front não pede o dado.
     */
    public function index(Request $request): JsonResponse
    {
        $widgets = DashboardWidget::query()
            ->ownedBy($request->user())
            ->orderBy('position')
            ->get();

        return response()->json(['data' => $widgets->map(function (DashboardWidget $widget) use ($request): array {
            $provider = $this->registry->find($widget->indicator_key);

            return $widget->toArray() + [
                'kind' => $provider === null ? 'insight' : 'provider',
                'available' => $provider === null || $this->registry->allows($request->user(), $provider),
            ];
        })->values()]);
    }

    public function store(StoreDashboardWidgetRequest $request): JsonResponse
    {
        $provider = $this->registry->find($request->string('indicator_key')->toString());
        $data = $request->validated();

        if ($provider !== null) {
            abort_unless($this->registry->allows($request->user(), $provider), 403, 'Você não tem permissão para este widget.');
            $data['config'] = $this->registry->resolveParams($provider, $data['config'] ?? []);
        }

        $widget = DashboardWidget::create($data + [
            'user_id' => $request->user()->id,
            'organization_id' => $this->scope->primaryOrganizationId($request->user()),
        ]);

        return response()->json(['data' => $widget], 201);
    }

    /**
     * `int $id` + `ownedBy()`, não `Route::model()` — mesmo padrão de
     * `ProductGroupController::destroy()`: binding implícito por id ignoraria o dono e abriria
     * IDOR (apagar o widget de outro usuário só de adivinhar o id).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        DashboardWidget::query()->ownedBy($request->user())->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
