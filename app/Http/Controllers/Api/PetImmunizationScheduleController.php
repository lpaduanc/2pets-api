<?php

namespace App\Http\Controllers\Api;

use App\Enums\PetImmunizationPlanStatus;
use App\Http\Controllers\Concerns\AuthorizesPetAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\PetImmunizationDoseResource;
use App\Models\PetImmunizationDose;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET pets/{pet}/immunization-schedule` — a próxima dose de cada protocolo ATIVO do pet,
 * derivada em leitura (contrato spec 13, regra de negócio 6). Sem nenhuma coluna de status
 * gravada, sem job.
 */
class PetImmunizationScheduleController extends Controller
{
    use AuthorizesPetAccess;

    public function __invoke(Request $request, int $petId): JsonResponse
    {
        $pet = $this->resolvePetForRead($request, $petId);

        $nextDosePerPlan = PetImmunizationDose::query()
            ->whereHas('plan', function ($query) use ($pet): void {
                $query->where('pet_id', $pet->id)->where('status', PetImmunizationPlanStatus::ACTIVE->value);
            })
            ->pending()
            ->with(['protocolDose.protocol.product', 'plan'])
            ->orderBy('scheduled_for')
            ->get()
            ->unique('plan_id')
            ->values();

        return response()->json(['data' => PetImmunizationDoseResource::collection($nextDosePerPlan)]);
    }
}
