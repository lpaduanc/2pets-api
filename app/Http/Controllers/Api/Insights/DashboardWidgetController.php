<?php

namespace App\Http\Controllers\Api\Insights;

use App\Http\Controllers\Controller;
use App\Http\Requests\Insights\StoreDashboardWidgetRequest;
use App\Models\DashboardWidget;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `dashboard-widgets` (index/store/destroy) — painel de controle pessoal, contrato
 * docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md. Widget é sempre PESSOAL
 * (`user_id`), nunca compartilhado entre a equipe — cada um monta o próprio painel.
 */
class DashboardWidgetController extends Controller
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function index(Request $request): JsonResponse
    {
        $widgets = DashboardWidget::query()
            ->ownedBy($request->user())
            ->orderBy('position')
            ->get();

        return response()->json(['data' => $widgets]);
    }

    public function store(StoreDashboardWidgetRequest $request): JsonResponse
    {
        $widget = DashboardWidget::create($request->validated() + [
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
