<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Immunization\StorePetImmunizationPlanRequest;
use App\Http\Resources\PetImmunizationPlanResource;
use App\Models\ImmunizationProtocol;
use App\Models\PetImmunizationPlan;
use App\Services\Medical\Immunization\ImmunizationProtocolSchedulerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `pets/{pet}/immunization-plans` — contrato docs/gap-simplesvet/contratos/13-contrato-api.md.
 * Iniciar um plano é decisão clínica (regra de negócio 7 continua valendo: registrar uma
 * aplicação AVULSA sem plano nenhum não passa por aqui, é o endpoint de saúde já existente).
 */
class PetImmunizationPlanController extends Controller
{
    use AuthorizesPetAccess;

    public function __construct(private readonly ImmunizationProtocolSchedulerService $scheduler) {}

    public function index(Request $request, int $petId): AnonymousResourceCollection
    {
        $pet = $this->resolvePetForRead($request, $petId);

        $plans = $pet->immunizationPlans()->with(['protocol.doses', 'doses.protocolDose'])->get();

        return PetImmunizationPlanResource::collection($plans);
    }

    public function store(StorePetImmunizationPlanRequest $request, int $petId): JsonResponse
    {
        $pet = $this->resolvePetForWrite($request, $petId);
        abort_unless($request->user()->isVeterinarian(), 403, 'Só um veterinário pode iniciar um protocolo de imunização.');

        $protocol = ImmunizationProtocol::findOrFail($request->validated('protocol_id'));
        $startedAt = Carbon::parse($request->validated('started_at') ?? now());

        $plan = $this->scheduler->startPlan($pet, $protocol, $startedAt);

        return response()->json([
            'data' => new PetImmunizationPlanResource($plan->load(['protocol.doses', 'doses.protocolDose'])),
        ], 201);
    }

    public function show(Request $request, int $petId, PetImmunizationPlan $plan): PetImmunizationPlanResource
    {
        $this->resolvePetForRead($request, $petId);
        abort_unless($plan->pet_id === $petId, 404);

        return new PetImmunizationPlanResource($plan->load(['protocol.doses', 'doses.protocolDose']));
    }
}
