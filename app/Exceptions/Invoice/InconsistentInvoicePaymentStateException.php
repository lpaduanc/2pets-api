<?php

namespace App\Exceptions\Invoice;

use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * `PaymentService::markInvoiceAsPaidManually` reaproveita o `Payment` não finalizado já
 * vinculado à fatura em vez de criar outro — mas se já existir um `Payment` `paid` para
 * esta fatura, `InvoiceStatus::canTransitionTo()` permite o self-transition `paid → paid`
 * (contrato §2.1), então a trava de `assertTransition` sozinha NÃO bloqueia um segundo
 * `mark-as-paid` sobre uma fatura já quitada. Esta exceção fecha essa lacuna explicitamente
 * em vez de deixar `reuseOrCreatePayment` reaproveitar/duplicar em silêncio.
 */
final class InconsistentInvoicePaymentStateException extends RuntimeException
{
    public static function alreadyHasPaidPayment(Invoice $invoice): self
    {
        return new self(sprintf(
            'A fatura #%d já tem um pagamento registrado como pago — não é possível registrar outro pagamento manual sobre ela.',
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
