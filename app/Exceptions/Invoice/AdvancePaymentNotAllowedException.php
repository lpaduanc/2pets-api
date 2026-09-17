<?php

namespace App\Exceptions\Invoice;

use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §3-bis.4:
 * pagamento adiantado é restrito à internação, por decisão explícita do dono do produto —
 * checado no `PaymentService`, não só escondido na UI.
 */
final class AdvancePaymentNotAllowedException extends RuntimeException
{
    public static function forInvoice(Invoice $invoice): self
    {
        return new self(sprintf(
            'Adiantamento disponível só para internação — a fatura #%d não é de internação. Use mark-as-paid.',
            $invoice->id,
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
