<?php

namespace App\Http\Controllers\Api;

use App\Enums\InventoryMovementType;
use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StoreInventoryRequest;
use App\Http\Requests\Inventory\UpdateInventoryRequest;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\Inventory\InventoryScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * O estoque é da ORGANIZAÇÃO quando ela existe (dois veterinários da mesma clínica veem o mesmo
 * estoque); do profissional individual quando não há organização (vet volante). Ver
 * `InventoryScopeResolver` e docs/vinculo-estoque-aplicacao-clinica.md item 5.
 */
class InventoryController extends Controller
{
    use PaginatesResults;

    /**
     * The stock screen shows counters (total, low stock, out of stock) computed from the
     * whole list it holds, so the default page has to cover a professional's whole inventory.
     */
    private const DEFAULT_PER_PAGE = 200;

    public function __construct(
        private readonly InventoryScopeResolver $scopeResolver,
    ) {}

    public function index(Request $request)
    {
        $query = $this->scopedQuery($request->user())->with('professional');

        $this->applyFilters($query, $request);

        $items = $query->orderBy('item_name')
            ->paginate($this->resolvePerPage($request, self::DEFAULT_PER_PAGE));

        return JsonResource::collection($items);
    }

    public function store(StoreInventoryRequest $request)
    {
        $data = $request->validated();
        $data['professional_id'] = $request->user()->id;
        $data['organization_id'] = $this->scopeResolver->primaryOrganizationId($request->user());

        $inventory = DB::transaction(function () use ($data, $request): Inventory {
            $inventory = Inventory::create($data);
            $this->recordInitialStock($inventory, $request->user());

            return $inventory;
        });

        return response()->json(['message' => 'Item added to inventory', 'item' => $inventory], 201);
    }

    public function show(Request $request, int $id)
    {
        $item = $this->scopedQuery($request->user())->with('professional')->findOrFail($id);

        return response()->json($item);
    }

    public function update(UpdateInventoryRequest $request, int $id)
    {
        return DB::transaction(function () use ($request, $id) {
            $inventory = $this->scopedQuery($request->user())->lockForUpdate()->findOrFail($id);
            $previousQuantity = $inventory->quantity;

            $inventory->update($request->validated());
            $this->recordManualAdjustment($inventory, $previousQuantity, $request->user());

            return response()->json(['message' => 'Inventory item updated', 'item' => $inventory]);
        });
    }

    public function destroy(Request $request, int $id)
    {
        $inventory = $this->scopedQuery($request->user())->findOrFail($id);
        $inventory->delete();

        return response()->json(['message' => 'Inventory item removed']);
    }

    /**
     * Escopo de visibilidade/escrita: organização ativa do usuário quando ele tem uma, senão só
     * os próprios itens sem organização (vet volante). Ver `InventoryScopeResolver`.
     */
    private function scopedQuery(User $user): Builder
    {
        return $this->scopeResolver->scopeQuery(Inventory::query(), $user);
    }

    /**
     * `low_stock=true` → itens no ou abaixo do mínimo configurado (`min_quantity`).
     * `category` → categoria exata. `search` → nome do item, case-insensitive.
     */
    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->boolean('low_stock')) {
            $query->lowStock();
        }

        if ($request->filled('category')) {
            $query->ofCategory((string) $request->input('category'));
        }

        if ($request->filled('search')) {
            $query->searchByName((string) $request->input('search'));
        }
    }

    private function recordInitialStock(Inventory $inventory, User $user): void
    {
        InventoryMovement::create([
            'inventory_id' => $inventory->id,
            'organization_id' => $inventory->organization_id,
            'professional_id' => $user->id,
            'type' => InventoryMovementType::PURCHASE_IN,
            'quantity_delta' => $inventory->quantity,
            'notes' => 'Estoque inicial do item.',
        ]);
    }

    /** Só gera movimento quando `update()` de fato mudou `quantity` — trocar preço/nome não é baixa. */
    private function recordManualAdjustment(Inventory $inventory, int $previousQuantity, User $user): void
    {
        $delta = $inventory->quantity - $previousQuantity;

        if ($delta === 0) {
            return;
        }

        InventoryMovement::create([
            'inventory_id' => $inventory->id,
            'organization_id' => $inventory->organization_id,
            'professional_id' => $user->id,
            'type' => $delta > 0 ? InventoryMovementType::ADJUSTMENT_INCREASE : InventoryMovementType::ADJUSTMENT_DECREASE,
            'quantity_delta' => $delta,
            'notes' => 'Ajuste manual de estoque.',
        ]);
    }
}
