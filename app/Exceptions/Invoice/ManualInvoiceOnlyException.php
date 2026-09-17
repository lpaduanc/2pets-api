<?php

namespace App\Exceptions\Invoice;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.5/§13.7:
 * `issue()` (`draft → pending`) só existe para fatura MANUAL (`POST /invoices`, sem
 * agendamento por trás). Fatura originada de atendimento já nasce `pending` ao iniciar
 * — não existe rascunho para emitir.
 */
final class ManualInvoiceOnlyException extends RuntimeException
{
    public static function forAppointmentInvoice(): self
    {
        return new self(
            'Esta fatura foi gerada automaticamente pelo atendimento e já nasceu pendente — não há rascunho para emitir.'
        );
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
