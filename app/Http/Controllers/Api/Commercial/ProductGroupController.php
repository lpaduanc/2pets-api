<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreProductGroupRequest;
use App\Http\Resources\Commercial\ProductGroupResource;
use App\Models\ProductGroup;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Grupos de produto/serviço — contrato docs/gap-simplesvet/08.
 *
 * `index` devolve a ÁRVORE (raízes com filhos), não a lista plana: o select de grupo do
 * cadastro e o filtro do BI precisam da hierarquia, e achatá-la no front significaria
 * reconstruir o mesmo agrupamento em cada tela.
 */
class ProductGroupController extends Controller
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $groups = $this->scope->scopeQuery(ProductGroup::query(), $request->user())
            ->whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->orderBy('name'), 'parent'])
            ->withCount(['products', 'services'])
            ->orderBy('name')
            ->get();

        return ProductGroupResource::collection($groups);
    }

    public function store(StoreProductGroupRequest $request): JsonResponse
    {
        $this->assertParentIsVisible($request);

        $group = ProductGroup::create(
            $request->validated() + $this->scope->ownershipFor($request->user())
        );

        return (new ProductGroupResource($group))
            ->additional(['message' => 'Grupo criado com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreProductGroupRequest $request, int $id): ProductGroupResource
    {
        $group = $this->scope->scopeQuery(ProductGroup::query(), $request->user())->findOrFail($id);
        $this->assertParentIsVisible($request, $group);

        $group->update($request->validated());

        return new ProductGroupResource($group->fresh(['parent']));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $group = $this->scope->scopeQuery(ProductGroup::query(), $request->user())
            ->withCount(['products', 'services', 'children'])
            ->findOrFail($id);

        // Apagar um grupo em uso deixaria o produto órfão de dimensão e sumiria com ele do
        // BI sem aviso. Preferimos recusar com o número na mensagem a fazer a limpeza por
        // conta própria.
        if ($group->products_count > 0 || $group->services_count > 0 || $group->children_count > 0) {
            return response()->json([
                'message' => 'Grupo em uso: '.$group->products_count.' produto(s), '
                    .$group->services_count.' serviço(s) e '.$group->children_count.' subgrupo(s). '
                    .'Mova ou remova os itens antes de excluir.',
            ], 422);
        }

        $group->delete();

        return response()->json(['message' => 'Grupo removido.']);
    }

    /**
     * `exists:product_groups,id` do Form Request só garante que o id EXISTE, não que ele é
     * visível para quem chamou — sem este teste, um grupo de outra clínica viraria pai do meu
     * e eu herdaria o markup padrão dela.
     */
    private function assertParentIsVisible(Request $request, ?ProductGroup $editing = null): void
    {
        $parentId = $request->integer('parent_id');

        if ($parentId === 0) {
            return;
        }

        abort_if($editing !== null && $parentId === $editing->id, 422, 'Um grupo não pode ser pai de si mesmo.');

        $visible = $this->scope->scopeQuery(ProductGroup::query(), $request->user())
            ->whereKey($parentId)
            ->exists();

        abort_unless($visible, 422, 'Grupo pai inválido.');
    }
}
