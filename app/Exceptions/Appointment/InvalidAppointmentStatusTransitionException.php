<?php

namespace App\Exceptions\Appointment;

use App\Enums\AppointmentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A consulta existe e o profissional pode operar sobre ela — não é 403 nem 404 — mas o status
 * pedido não é alcançável a partir do status atual (ex.: `completed` → `scheduled`).
 */
final class InvalidAppointmentStatusTransitionException extends RuntimeException
{
    public static function notAllowed(AppointmentStatus $from, AppointmentStatus $to): self
    {
        return new self(sprintf(
            'Não é possível mudar o status de "%s" para "%s".',
            $from->label(),
            $to->label(),
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
