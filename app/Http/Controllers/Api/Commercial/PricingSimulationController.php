<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\SimulatePricingRequest;
use App\Services\Commercial\Pricing\PricingSimulator;
use Illuminate\Http\JsonResponse;

/**
 * Simulação de preço do cadastro de produto/serviço (`POST pricing/simulate`) —
 * docs/gap-petflow/PLANO.md, 1.4. Só pré-visualiza: nada é gravado.
 */
class PricingSimulationController extends Controller
{
    public function __construct(private readonly PricingSimulator $simulator) {}

    public function __invoke(SimulatePricingRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->simulator->simulate($request->user(), $request->validated())]);
    }
}
