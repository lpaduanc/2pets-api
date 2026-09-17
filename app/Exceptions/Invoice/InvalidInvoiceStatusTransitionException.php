<?php

namespace App\Exceptions\Invoice;

use App\Enums\InvoiceStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A fatura existe e o requisitante pode operar sobre ela, mas o status atual não permite a
 * transição pedida — contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md
 * §2.1. Mesmo padrão de `InvalidAppointmentStatusTransitionException`.
 */
final class InvalidInvoiceStatusTransitionException extends RuntimeException
{
    public static function notAllowed(InvoiceStatus $from, InvoiceStatus $to): self
    {
        return new self(sprintf(
            'Não é possível mudar o status da fatura de "%s" para "%s".',
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
