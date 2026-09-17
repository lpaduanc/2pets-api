<?php

namespace App\Services\Invoice;

use App\Enums\InvoiceStatus;
use App\Exceptions\Invoice\InvalidInvoiceStatusTransitionException;
use App\Exceptions\Invoice\InvoiceHasNoItemsException;
use App\Exceptions\Invoice\ManualInvoiceOnlyException;
use App\Models\Invoice;

/**
 * Transições de estado de `Invoice` fora do pagamento (que é responsabilidade de
 * `PaymentService::markInvoiceAsPaid`) — contrato
 * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §2.1/§4/§13.5/§13.7.
 *
 * `InvoicePolicy` decide QUEM pode chamar `issue`/`cancel`; este service decide se o ESTADO
 * atual permite a transição — mesma separação de `AppointmentStatusTransitionService`.
 */
final class InvoiceLifecycleService
{
    /**
     * `draft → pending` — só para fatura MANUAL (`appointment_id === null`). Contrato
     * §13.5: fatura de atendimento já nasce `pending` ao iniciar
     * (`AppointmentInvoiceService`), não existe rascunho para emitir.
     */
    public function issue(Invoice $invoice): Invoice
    {
        if ($invoice->appointment_id !== null) {
            throw ManualInvoiceOnlyException::forAppointmentInvoice();
        }

        $this->assertTransition($invoice, InvoiceStatus::PENDING);

        if (empty($invoice->items)) {
            throw InvoiceHasNoItemsException::cannotIssue();
        }

        $invoice->update(['status' => InvoiceStatus::PENDING->value]);

        return $invoice;
    }

    public function cancel(Invoice $invoice, ?string $reason): Invoice
    {
        $this->assertTransition($invoice, InvoiceStatus::CANCELLED);

        $invoice->update([
            'status' => InvoiceStatus::CANCELLED->value,
            'notes' => $this->appendCancellationNote($invoice, $reason),
        ]);

        return $invoice;
    }

    private function assertTransition(Invoice $invoice, InvoiceStatus $target): void
    {
        $current = InvoiceStatus::from($invoice->status);

        if (! $current->canTransitionTo($target)) {
            throw InvalidInvoiceStatusTransitionException::notAllowed($current, $target);
        }
    }

    private function appendCancellationNote(Invoice $invoice, ?string $reason): ?string
    {
        if ($reason === null) {
            return $invoice->notes;
        }

        $entry = 'Cancelamento: '.$reason;

        return $invoice->notes !== null ? $invoice->notes.PHP_EOL.$entry : $entry;
    }
}
