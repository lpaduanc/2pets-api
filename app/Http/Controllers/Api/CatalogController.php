<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCatalogItemRequest;
use App\Http\Resources\Catalog\CatalogItemResource;
use App\Models\AppointmentType;
use App\Models\Holiday;
use App\Services\Commercial\CommercialScopeResolver;
use App\Support\Catalog\CatalogTypeRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * CRUD genérico dos cadastros configuráveis por dono (item 23 do backlog gap-simplesvet) —
 * `coats`, `occupations`, `customer-sources`, `loss-reasons`, `holidays`,
 * `hospitalization-boxes`. Mesmo padrão de dono de `StockExitReasonController`
 * (`CommercialScopeResolver`), reaproveitado em vez de duplicado.
 *
 * Leitura é livre para qualquer usuário autenticado (os selects do app inteiro dependem
 * disso); mutação exige `catalog.manage`, aplicado na rota (ver `routes/api.php`), nunca
 * checado aqui dentro — autorização de rota não é regra de negócio de controller.
 */
class CatalogController extends Controller
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function index(Request $request, string $type): AnonymousResourceCollection
    {
        $modelClass = CatalogTypeRegistry::modelClassFor($type);

        $query = $this->scope->scopeQuery($modelClass::query(), $request->user())
            // `holidays`: o feriado NACIONAL (`organization_id`/`professional_id` nulos, só o
            // `HolidaySeeder` cria) não é alcançado pelo `scopeQuery()` genérico (que nunca
            // devolve linha com os dois nulos) — inclui aqui, só para leitura. `update`/
            // `destroy` continuam usando a query escopada normal, então o nacional já sai
            // protegido contra edição pelo catálogo sem código extra.
            ->when(
                is_a($modelClass, Holiday::class, true),
                fn ($q) => $q->orWhere(fn ($global) => $global->whereNull('organization_id')->whereNull('professional_id'))
            )
            ->when($request->has('active'), fn ($q) => $q->where('active', $request->boolean('active')))
            // Filtro específico de `appointment-types` (spec 14): popular o select de
            // "tipo" pela categoria do serviço já escolhido no formulário de agendamento.
            // Guardado por model para não quebrar catálogos sem essa coluna.
            ->when(
                $request->filled('category') && is_a($modelClass, AppointmentType::class, true),
                fn ($q) => $q->where('category', $request->string('category')->toString())
            )
            ->orderBy('name');

        return CatalogItemResource::collection($query->get());
    }

    public function store(StoreCatalogItemRequest $request, string $type): JsonResponse
    {
        $modelClass = CatalogTypeRegistry::modelClassFor($type);

        try {
            $item = $modelClass::create($request->validated() + $this->scope->ownershipFor($request->user()));
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateNameResponse($request->string('name')->toString());
        }

        return (new CatalogItemResource($item))->response()->setStatusCode(201);
    }

    private function duplicateNameResponse(string $name): JsonResponse
    {
        return response()->json([
            'message' => "Já existe um item chamado \"{$name}\" neste cadastro.",
        ], 422);
    }

    public function update(StoreCatalogItemRequest $request, string $type, int $id): CatalogItemResource|JsonResponse
    {
        $item = $this->findOwnedOrFail($type, $id, $request);

        try {
            $item->update($request->validated());
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateNameResponse((string) $request->input('name', $item->name));
        }

        return new CatalogItemResource($item);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $item = $this->findOwnedOrFail($type, $id, $request);
        $item->delete();

        return response()->json(['message' => 'Item removido.']);
    }

    private function findOwnedOrFail(string $type, int $id, Request $request): \Illuminate\Database\Eloquent\Model
    {
        $modelClass = CatalogTypeRegistry::modelClassFor($type);

        return $this->scope->scopeQuery($modelClass::query(), $request->user())->findOrFail($id);
    }
}
