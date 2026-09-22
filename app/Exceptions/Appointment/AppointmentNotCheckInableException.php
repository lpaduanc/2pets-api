<?php

namespace App\Exceptions\Appointment;

use App\Enums\AppointmentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Item 21 do backlog gap-simplesvet, regra 3: check-in não pula estado. Só é possível marcar
 * `checked_in_at` em um agendamento `confirmed`/`scheduled` do DIA CORRENTE — check-in de
 * outro dia é erro de operação (recepção no agendamento errado), não uma transição válida.
 */
final class AppointmentNotCheckInableException extends RuntimeException
{
    public static function wrongDay(): self
    {
        return new self('Este agendamento não é de hoje — confira se está na agenda correta.');
    }

    public static function forStatus(AppointmentStatus $status): self
    {
        return new self(sprintf(
            'Este agendamento está "%s" e não aceita check-in agora.',
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
