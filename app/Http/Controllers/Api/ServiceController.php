<?php

namespace App\Http\Controllers\Api;

use App\Enums\ServiceCategory;
use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ServiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Service::where('professional_id', $request->user()->id);
        if ($request->has('active')) {
            $query->where('active', filter_var($request->active, FILTER_VALIDATE_BOOLEAN));
        }
        $services = $query->orderBy('name')->get();

        return response()->json($services);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            // Achado ao migrar docs/atendimento-veterinario/10-taxonomia-servico-tipo-atendimento.md:
            // esta validação só aceitava 5 das 15 categorias reais, enquanto o CHECK do banco
            // (migration 2026_09_22_100000) já tinha sido alinhado a `ServiceCategory` — ou
            // seja, nenhum profissional conseguia cadastrar `imaging`/`laboratory`/etc. por
            // aqui mesmo depois do banco aceitar. Corrigido junto por ser o mesmo bug de
            // taxonomia paralela que motivou esta tarefa.
            'category' => ['required', Rule::enum(ServiceCategory::class)],
            'duration' => 'required|integer',
            'price' => 'required|numeric',
            'active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
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

    public function update(Request $request, $id)
    {
        $service = Service::where('professional_id', $request->user()->id)->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'category' => ['sometimes', 'required', Rule::enum(ServiceCategory::class)],
            'duration' => 'sometimes|required|integer',
            'price' => 'sometimes|required|numeric',
            'active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $service->update($validator->validated());

        return response()->json(['message' => 'Service updated', 'service' => $service]);
    }

    public function destroy($id)
    {
        $service = Service::where('professional_id', request()->user()->id)->findOrFail($id);
        $service->delete();

        return response()->json(['message' => 'Service removed']);
    }
}
