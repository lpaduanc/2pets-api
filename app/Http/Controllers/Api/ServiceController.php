<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreServiceRequest;
use App\Http\Requests\Commercial\UpdateServiceRequest;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function index(Request $request)
    {
        $query = Service::where('professional_id', $request->user()->id);
        if ($request->has('active')) {
            $query->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }
        $services = $query->orderBy('name')->get();

        return response()->json($services);
    }

    public function store(StoreServiceRequest $request)
    {
        $data = $request->validated();
        $this->assertGroupIsVisible($request, $data['product_group_id'] ?? null);
        $data['professional_id'] = $request->user()->id;
        // Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §12.6.
        $data['organization_id'] = $request->user()->activeOrganizationId();

        $service = Service::create($data);

        return response()->json(['message' => 'Service created', 'service' => $service], 201);
    }

    public function show($id)
    {
        $service = Service::where('professional_id', request()->user()->id)->findOrFail($id);

        return response()->json($service);
    }

    public function update(UpdateServiceRequest $request, $id)
    {
        $service = Service::where('professional_id', $request->user()->id)->findOrFail($id);

        $data = $request->validated();

        if (array_key_exists('product_group_id', $data)) {
            $this->assertGroupIsVisible($request, $data['product_group_id']);
        }

        $service->update($data);

        return response()->json(['message' => 'Service updated', 'service' => $service]);
    }

    public function destroy($id)
    {
        $service = Service::where('professional_id', request()->user()->id)->findOrFail($id);
        $service->delete();

        return response()->json(['message' => 'Service removed']);
    }

    /**
     * `exists:product_groups,id` só garante que o grupo existe, não que é visível para quem
     * chamou — sem este teste, um `product_group_id` de outra clínica entraria no cadastro do
     * serviço (mesma armadilha corrigida em `ProductGroupController::assertParentIsVisible`).
     */
    private function assertGroupIsVisible(Request $request, ?int $groupId): void
    {
        if ($groupId === null) {
            return;
        }

        $visible = $this->scope->scopeQuery(ProductGroup::query(), $request->user())
            ->whereKey($groupId)
            ->exists();

        abort_unless($visible, 422, 'Grupo inválido.');
    }
}
