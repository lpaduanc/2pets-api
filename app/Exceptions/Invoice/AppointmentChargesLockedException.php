<?php

namespace App\Exceptions\Invoice;

use App\Enums\InvoiceStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Contrato docs/atendimento-veterinario/09-faturamento-do-atendimento.md §13.3: a comanda
 * (`AppointmentCharge`) só pode ser criada/editada/removida enquanto o atendimento está
 * em andamento e a fatura (se já existir) não foi paga/cancelada — itens congelam no
 * PAGAMENTO (invariante 13), não na emissão.
 */
final class AppointmentChargesLockedException extends RuntimeException
{
    public static function forInactiveAppointment(): self
    {
        return new self(
            'Este atendimento ainda não começou ou já foi concluído — as linhas de cobrança não podem ser alteradas.'
        );
    }

    public static function forInvoiceStatus(InvoiceStatus $status): self
    {
        return new self(match ($status) {
            InvoiceStatus::PAID => 'Esta fatura já foi paga — os itens estão congelados. Para corrigir, cancele e emita uma nova.',
            default => 'Esta fatura não aceita mais alterações de itens.',
        });
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
