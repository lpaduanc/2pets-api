<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appointment\StoreNewPatientAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\PetResource;
use App\Http\Resources\Professional\NewPatientTutorResource;
use App\Services\Professional\NewPatientAppointmentService;
use App\Services\Transport\PetTransportService;
use App\Services\Transport\TransportQuoteService;
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
        private readonly PetTransportService $transports,
        private readonly TransportQuoteService $transportQuotes,
    ) {}

    public function store(StoreNewPatientAppointmentRequest $request): JsonResponse
    {
        $transport = $request->validated('transport');
        $organizationId = $request->user()->activeOrganizationId();

        // PLANO 3.7: fora da área recusa antes de criar tutor, pet e agendamento.
        if ($transport !== null) {
            $this->transportQuotes->quote($organizationId, $request->user()->id, $transport['address']);
        }

        $result = $this->newPatientAppointmentService->handle(
            $request->user(),
            $request->validated(),
            $request->ip(),
            $request->userAgent(),
        );

        if ($transport !== null) {
            $this->transports->attach($result->appointment, $transport, $organizationId);
        }

        return response()->json([
            'data' => [
                'appointment' => new AppointmentResource($result->appointment->load(['client', 'pet', 'invoice:id,appointment_id', 'transport'])),
                'pet' => new PetResource($result->pet),
                'tutor' => new NewPatientTutorResource($result->tutor),
                'claim_link_sent' => $result->claimLinkSent,
            ],
        ], 201);
    }
}
