<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\PreviewServiceCostRequest;
use App\Http\Requests\Commercial\UpdateServiceSuppliesRequest;
use App\Models\Service;
use App\Services\Commercial\ServiceCost\ServiceSupplyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ficha técnica de insumos do serviço e prévia de custo/margem — docs/gap-petflow/PLANO.md, 1.3.
 * Mesmo escopo do `ServiceController` (serviço do próprio profissional): quem edita o serviço
 * edita a ficha.
 */
class ServiceSupplyController extends Controller
{
    public function __construct(private readonly ServiceSupplyService $supplies) {}

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->supplies->breakdown($this->ownService($request, $id))]);
    }

    public function update(UpdateServiceSuppliesRequest $request, int $id): JsonResponse
    {
        $service = $this->ownService($request, $id);

        return response()->json(['data' => $this->supplies->replace($service, $request->validated())]);
    }

    public function preview(PreviewServiceCostRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->supplies->preview($request->user(), $request->validated())]);
    }

    private function ownService(Request $request, int $id): Service
    {
        return Service::query()->where('professional_id', $request->user()->id)->findOrFail($id);
    }
}
