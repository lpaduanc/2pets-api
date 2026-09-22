<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Immunization\ApplyPetImmunizationDoseRequest;
use App\Http\Resources\PetImmunizationDoseResource;
use App\Models\PetImmunizationDose;
use App\Models\PetImmunizationPlan;
use App\Services\Medical\Immunization\ImmunizationDoseApplicationService;
use App\Services\Medical\Immunization\ImmunizationProtocolSchedulerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PetImmunizationDoseController extends Controller
{
    use AuthorizesPetAccess;

    public function __construct(
        private readonly ImmunizationDoseApplicationService $applicationService,
        private readonly ImmunizationProtocolSchedulerService $scheduler,
    ) {}

    public function apply(
        ApplyPetImmunizationDoseRequest $request,
        int $petId,
        PetImmunizationPlan $plan,
        PetImmunizationDose $dose,
    ): JsonResponse {
        $this->resolvePetForWrite($request, $petId);
        $this->assertBelongsToPlan($plan, $dose, $petId);
        abort_unless($request->user()->isVeterinarian(), 403, 'Só um veterinário pode registrar a aplicação.');

        $appliedAt = Carbon::parse($request->validated('applied_at') ?? now());

        $dose = $this->applicationService->apply(
            $dose,
            $request->user(),
            $appliedAt,
            $request->validated('product_id'),
            $request->validated('product_batch_id'),
            (bool) $request->validated('confirm_expired', false),
            $request->validated('notes'),
        );

        return response()->json(['data' => new PetImmunizationDoseResource($dose->load('protocolDose'))]);
    }

    public function skip(Request $request, int $petId, PetImmunizationPlan $plan, PetImmunizationDose $dose): JsonResponse
    {
        $this->resolvePetForWrite($request, $petId);
        $this->assertBelongsToPlan($plan, $dose, $petId);
        abort_unless($request->user()->isVeterinarian(), 403, 'Só um veterinário pode pular uma dose do protocolo.');

        $dose = $this->scheduler->skipDose($dose);

        return response()->json(['data' => new PetImmunizationDoseResource($dose->load('protocolDose'))]);
    }

    private function assertBelongsToPlan(PetImmunizationPlan $plan, PetImmunizationDose $dose, int $petId): void
    {
        abort_unless($plan->pet_id === $petId && $dose->plan_id === $plan->id, 404);
    }
}
