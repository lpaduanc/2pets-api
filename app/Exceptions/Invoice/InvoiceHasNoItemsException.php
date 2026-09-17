<?php

namespace App\Exceptions\Invoice;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §4: `issue()` exige
 * `items` não vazio — uma fatura sem nenhuma linha não pode virar cobrança real.
 */
final class InvoiceHasNoItemsException extends RuntimeException
{
    public static function cannotIssue(): self
    {
        return new self('Não é possível emitir uma fatura sem nenhuma linha de cobrança.');
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
