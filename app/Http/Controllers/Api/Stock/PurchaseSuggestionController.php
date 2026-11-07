<?php

namespace App\Http\Controllers\Api\Stock;

use App\Http\Controllers\Controller;
use App\Http\Resources\Stock\PurchaseOrderResource;
use App\Services\Commercial\Authorization\SalePermissionGate;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Stock\Projection\PurchaseProjectionService;
use App\Services\Stock\Projection\PurchaseSuggestionOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pedidos de compra gerados das sugestões da projeção (PLANO 3.4). Escrita com
 * `products.update` pelo cargo na organização, como o resto da escrita de estoque.
 */
class PurchaseSuggestionController extends Controller
{
    private const MANAGE = 'products.update';

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly SalePermissionGate $permissions,
        private readonly PurchaseSuggestionOrderService $suggestions,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->permissions->allowsInOrganization($user, $this->scope->primaryOrganizationId($user), self::MANAGE), 403, 'Você não tem permissão para gerar pedidos de compra nesta organização.');

        $data = $request->validate([
            'window' => ['nullable', 'integer', Rule::in(PurchaseProjectionService::WINDOWS)],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'items.*.supplier_id' => ['nullable', 'integer'],
        ]);

        $orders = $this->suggestions->generate($user, $data['items'], (int) ($data['window'] ?? PurchaseProjectionService::DEFAULT_WINDOW));

        return response()->json(['data' => PurchaseOrderResource::collection($orders)->resolve($request)], 201);
    }
}
