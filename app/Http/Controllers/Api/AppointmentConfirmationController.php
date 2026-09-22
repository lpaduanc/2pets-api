<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appointment\RejectAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Services\Appointment\AppointmentConfirmationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `professional/appointments/{id}/confirm|reject` — Fase 4 do fluxo de agendamento.
 * Contrato EXPLÍCITO (dois endpoints, não o `PUT` genérico de edição): o app mostra dois
 * botões numa notificação push, não abre um formulário de edição. `AppointmentPolicy`
 * decide quem pode; `AppointmentConfirmationService` decide a transição (reaproveitando
 * `AppointmentStatusTransitionService`, a única máquina de estado de `appointments.status`).
 */
class AppointmentConfirmationController extends Controller
{
    public function __construct(private readonly AppointmentConfirmationService $confirmationService) {}

    /** POST professional/appointments/{id}/confirm */
    public function confirm(Request $request, int $id): AppointmentResource
    {
        $appointment = Appointment::findOrFail($id);
        Gate::forUser($request->user())->authorize('confirm', $appointment);

        $confirmed = $this->confirmationService->confirm($appointment);

        return (new AppointmentResource($confirmed->load(['client', 'pet', 'service'])))
            ->additional(['message' => 'Agendamento confirmado.']);
    }

    /** POST professional/appointments/{id}/reject */
    public function reject(RejectAppointmentRequest $request, int $id): AppointmentResource
    {
        $appointment = Appointment::findOrFail($id);
        Gate::forUser($request->user())->authorize('reject', $appointment);

        $rejected = $this->confirmationService->reject($appointment, $request->input('reason'));

        return (new AppointmentResource($rejected->load(['client', 'pet', 'service'])))
            ->additional(['message' => 'Agendamento recusado.']);
    }
}
