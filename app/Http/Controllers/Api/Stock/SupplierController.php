<?php

namespace App\Http\Controllers\Api\Stock;

use App\Http\Controllers\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Stock\StoreSupplierRequest;
use App\Http\Resources\Stock\SupplierResource;
use App\Models\Supplier;
use App\Services\Commercial\CommercialScopeResolver;
use App\Support\TaxpayerDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Fornecedores — contrato docs/gap-simplesvet/06. Escopo por `CommercialScopeResolver`. */
class SupplierController extends Controller
{
    use PaginatesResults;

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $suppliers = $this->scope->scopeQuery(Supplier::query(), $request->user())
            ->withCount('purchases')
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $term = $request->string('search')->toString();
                // Documento sem máscara e maiúsculo: o CNPJ alfanumérico tem letra.
                $document = (string) TaxpayerDocument::normalize($term);
                $q->where(function (Builder $inner) use ($term, $document): void {
                    $inner->where('legal_name', 'ilike', "%{$term}%")
                        ->orWhere('trade_name', 'ilike', "%{$term}%")
                        ->orWhere('sales_rep_name', 'ilike', "%{$term}%");

                    if (preg_match('/\d/', $document) === 1) {
                        $inner->orWhere('document', 'like', "%{$document}%");
                    }
                });
            })
            ->when($request->has('active'), fn (Builder $q) => $q->where('active', $request->boolean('active')))
            ->orderBy('legal_name')
            ->paginate($this->resolvePerPage($request, 50))
            ->withQueryString();

        return SupplierResource::collection($suppliers);
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $this->assertUniqueDocument($request, $request->input('document'));

        $supplier = Supplier::create($request->validated() + $this->scope->ownershipFor($request->user()));

        return (new SupplierResource($supplier))
            ->additional(['message' => 'Fornecedor cadastrado com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id): SupplierResource
    {
        return new SupplierResource(
            $this->scope->scopeQuery(Supplier::query(), $request->user())->withCount('purchases')->findOrFail($id)
        );
    }

    public function update(StoreSupplierRequest $request, int $id): SupplierResource
    {
        $supplier = $this->scope->scopeQuery(Supplier::query(), $request->user())->findOrFail($id);
        $this->assertUniqueDocument($request, $request->input('document'), $supplier->id);

        $supplier->update($request->validated());

        return new SupplierResource($supplier->loadCount('purchases'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $supplier = $this->scope->scopeQuery(Supplier::query(), $request->user())->withCount('purchases')->findOrFail($id);

        // Fornecedor com compra é histórico fiscal: desativa, não some.
        if ($supplier->purchases_count > 0) {
            return response()->json([
                'message' => 'Fornecedor com '.$supplier->purchases_count.' compra(s) lançada(s). Desative-o em vez de excluir.',
            ], 422);
        }

        $supplier->delete();

        return response()->json(['message' => 'Fornecedor removido.']);
    }

    private function assertUniqueDocument(Request $request, ?string $document, ?int $ignoreId = null): void
    {
        if (empty($document)) {
            return;
        }

        $exists = $this->scope->scopeQuery(Supplier::query(), $request->user())
            ->where('document', $document)
            ->when($ignoreId !== null, fn (Builder $q) => $q->whereKeyNot($ignoreId))
            ->exists();

        abort_if($exists, 422, 'Já existe um fornecedor com este CNPJ/CPF.');
    }
}
