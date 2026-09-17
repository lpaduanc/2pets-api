<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppointmentCharge\StoreAppointmentChargeRequest;
use App\Http\Requests\AppointmentCharge\UpdateAppointmentChargeRequest;
use App\Http\Resources\AppointmentChargeResource;
use App\Models\Appointment;
use App\Services\Invoice\AppointmentChargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * `professional/appointments/{id}/charges` — contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.3/§13.7. MOVEU de
 * `professional/medical-records/{id}/charges`: banho e tosa não gera prontuário, então a
 * comanda pendura no agendamento, que todo atendimento tem. Controller fino: toda regra
 * de negócio (trava de mutabilidade, resolução de catálogo, sincronia com a fatura) vive
 * em `AppointmentChargeService`; `AppointmentPolicy::manageCharges` decide quem opera.
 */
class AppointmentChargeController extends Controller
{
    public function __construct(private readonly AppointmentChargeService $chargeService) {}

    public function index(Request $request, int $id): AnonymousResourceCollection
    {
        $appointment = Appointment::findOrFail($id);
        Gate::forUser($request->user())->authorize('manageCharges', $appointment);

        $charges = $appointment->charges()->orderBy('created_at')->get();

        return AppointmentChargeResource::collection($charges);
    }

    public function store(StoreAppointmentChargeRequest $request, int $id): JsonResponse
    {
        $appointment = Appointment::findOrFail($id);
        Gate::forUser($request->user())->authorize('manageCharges', $appointment);

        $charge = $this->chargeService->create($appointment, $request->user(), $request->validated());

        return (new AppointmentChargeResource($charge))
            ->additional(['message' => 'Linha de cobrança lançada.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateAppointmentChargeRequest $request, int $id, int $chargeId): JsonResponse
    {
        $appointment = Appointment::findOrFail($id);
        Gate::forUser($request->user())->authorize('manageCharges', $appointment);

        $charge = $this->chargeService->update($appointment, $chargeId, $request->validated());

        return (new AppointmentChargeResource($charge))
            ->additional(['message' => 'Linha de cobrança atualizada.'])
            ->response();
    }

    public function destroy(Request $request, int $id, int $chargeId): JsonResponse
    {
        $appointment = Appointment::findOrFail($id);
        Gate::forUser($request->user())->authorize('manageCharges', $appointment);

        $this->chargeService->delete($appointment, $chargeId);

        return response()->json(['message' => 'Linha de cobrança removida.']);
    }
}
