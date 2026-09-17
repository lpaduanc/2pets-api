<?php

namespace App\Exceptions\Invoice;

use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §3-bis.3:
 * quando os adiantamentos já ultrapassaram o total da fatura, o acerto final não devolve
 * dinheiro sozinho — o sistema não custodiou o valor (adiantamento é sempre
 * `manual_offline`). O profissional precisa declarar o que fez com a sobra
 * (`credit_resolution`), mesmo espírito de "declaração auditável, não inferência" que já
 * rege `payment_channel`.
 */
final class CreditResolutionRequiredException extends RuntimeException
{
    public static function forInvoice(Invoice $invoice): self
    {
        return new self(sprintf(
            'A fatura #%d tem adiantamentos que já cobrem o total — informe "credit_resolution" '.
            'declarando o que foi feito com a sobra antes de fechar o acerto final.',
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
