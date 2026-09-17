<?php

namespace App\Exceptions\Appointment;

use App\Enums\AppointmentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * O agendamento existe e pertence ao profissional autenticado, mas o status atual não permite
 * iniciar atendimento — contrato docs/atendimento-veterinario/08-consulta-autorizada-por-
 * agendamento.md §A. Só `scheduled`, `confirmed` e `in_progress` (idempotente) iniciam.
 */
final class UnstartableAppointmentException extends RuntimeException
{
    public static function forStatus(AppointmentStatus $status): self
    {
        return new self(sprintf(
            'Este agendamento está "%s" e não pode iniciar atendimento agora.',
            $status->label(),
        ));
    }

    /** Resultado de negócio esperado, não incidente: não polui o log com stack trace. */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
