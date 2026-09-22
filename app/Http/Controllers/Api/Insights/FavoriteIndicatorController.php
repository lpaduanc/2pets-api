<?php

namespace App\Http\Controllers\Api\Insights;

use App\Http\Controllers\Controller;
use App\Http\Requests\Insights\StoreFavoriteIndicatorRequest;
use App\Models\FavoriteIndicator;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `favorite-indicators` (index/store/destroy) — aba "Favoritos" do BI, contrato
 * docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md.
 */
class FavoriteIndicatorController extends Controller
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function index(Request $request): JsonResponse
    {
        $favorites = FavoriteIndicator::query()
            ->ownedBy($request->user())
            ->latest()
            ->get();

        return response()->json(['data' => $favorites]);
    }

    public function store(StoreFavoriteIndicatorRequest $request): JsonResponse
    {
        $favorite = FavoriteIndicator::create($request->validated() + [
            'user_id' => $request->user()->id,
            'organization_id' => $this->scope->primaryOrganizationId($request->user()),
        ]);

        return response()->json(['data' => $favorite], 201);
    }

    /**
     * `int $id` + `ownedBy()`, não `Route::model()` — mesmo padrão de
     * `ProductGroupController::destroy()`: binding implícito por id ignoraria o dono e abriria
     * IDOR (apagar o favorito de outro usuário só de adivinhar o id).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        FavoriteIndicator::query()->ownedBy($request->user())->findOrFail($id)->delete();

        return response()->json(null, 204);
    }
}
