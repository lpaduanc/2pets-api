<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appointment\StoreNewPatientAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\PetResource;
use App\Http\Resources\Professional\NewPatientTutorResource;
use App\Services\Professional\NewPatientAppointmentService;
use Illuminate\Http\JsonResponse;

/**
 * `POST professional/appointments/new-patient` — contrato
 * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §1.
 *
 * Endpoint separado de `AppointmentController::store` de propósito: aquele exige
 * `client_id`/`pet_id` já existentes; este cria (ou reaproveita) tutor e pet na mesma
 * transação. Toda a regra de negócio vive em `NewPatientAppointmentService` e nos
 * colaboradores que ele orquestra — o controller só valida, delega e formata a resposta.
 */
class NewPatientAppointmentController extends Controller
{
    public function __construct(
        private readonly NewPatientAppointmentService $newPatientAppointmentService,
    ) {}

    public function store(StoreNewPatientAppointmentRequest $request): JsonResponse
    {
        $result = $this->newPatientAppointmentService->handle(
            $request->user(),
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json([
            'data' => [
                'appointment' => new AppointmentResource($result->appointment->load(['client', 'pet', 'invoice:id,appointment_id'])),
                'pet' => new PetResource($result->pet),
                'tutor' => new NewPatientTutorResource($result->tutor),
                'claim_link_sent' => $result->claimLinkSent,
            ],
        ], 201);
    }
}
