<?php

namespace App\Http\Controllers\Api\Stock;

use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\StoreStockExitReasonRequest;
use App\Http\Resources\Stock\StockExitReasonResource;
use App\Models\StockExitReason;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Motivos de saída de estoque — `/v3/comercial/motivos-de-saida` do SimplesVet (doc 07). */
class StockExitReasonController extends Controller
{
    /** Sugestão inicial para a clínica não começar com a lista vazia. */
    private const DEFAULTS = [
        ['name' => 'Uso interno', 'affects_cost' => true],
        ['name' => 'Quebra / avaria', 'affects_cost' => true],
        ['name' => 'Vencimento', 'affects_cost' => true],
        ['name' => 'Amostra / brinde', 'affects_cost' => false],
    ];

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = fn () => $this->scope->scopeQuery(StockExitReason::query(), $request->user());

        if (! $query()->withTrashed()->exists()) {
            foreach (self::DEFAULTS as $default) {
                StockExitReason::create($default + $this->scope->ownershipFor($request->user()));
            }
        }

        return StockExitReasonResource::collection(
            $query()->when($request->has('active'), fn ($q) => $q->where('active', $request->boolean('active')))
                ->orderBy('name')
                ->get()
        );
    }

    public function store(StoreStockExitReasonRequest $request): JsonResponse
    {
        $reason = StockExitReason::create($request->validated() + $this->scope->ownershipFor($request->user()));

        return (new StockExitReasonResource($reason))->response()->setStatusCode(201);
    }

    public function update(StoreStockExitReasonRequest $request, int $id): StockExitReasonResource
    {
        $reason = $this->scope->scopeQuery(StockExitReason::query(), $request->user())->findOrFail($id);
        $reason->update($request->validated());

        return new StockExitReasonResource($reason);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $reason = $this->scope->scopeQuery(StockExitReason::query(), $request->user())->findOrFail($id);

        // Motivo já usado continua no histórico (soft delete + `withTrashed` no movimento).
        $reason->delete();

        return response()->json(['message' => 'Motivo removido.']);
    }
}
