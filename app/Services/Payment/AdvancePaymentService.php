<?php

namespace App\Services\Payment;

use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\ServiceCategory;
use App\Exceptions\Invoice\AdvancePaymentNotAllowedException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Commercial\InvoicePaymentCashRecorder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `POST professional/invoices/{id}/advance-payment` — contrato
 * docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md §3-bis.4:
 * recebimento parcial registrado, restrito à internação. Extraído de `PaymentService`
 * (já um arquivo legado, >200 linhas antes desta tarefa) porque um adiantamento nunca
 * reaproveita `Payment` existente nem participa de `reuseOrCreatePayment` — é um objeto de
 * regra próprio, sem acoplamento com a máquina de acerto final.
 */
final class AdvancePaymentService
{
    public function __construct(private readonly InvoicePaymentCashRecorder $cashRecorder) {}

    /**
     * Nunca reaproveita um `Payment` existente (cada adiantamento é um evento real
     * distinto) e nunca fecha a fatura sozinho — ela continua `pending` até o acerto
     * final, mesmo depois deste `Payment` já estar `paid` (invariante 13 do contrato de
     * faturamento, preservada sem alteração de código nela).
     */
    public function record(
        Invoice $invoice,
        User $recordedBy,
        PaymentMethod $method,
        float $amount,
        Carbon $paidAt,
        ?string $notes,
    ): Payment {
        $this->assertAllowed($invoice);

        return DB::transaction(function () use ($invoice, $recordedBy, $method, $amount, $paidAt, $notes): Payment {
            $payment = $this->createPayment($invoice, $recordedBy, $method, $amount, $paidAt, $notes);

            // Adiantamento recebido no balcão entra no caixa aberto de quem recebeu (doc
            // gap-simplesvet/01). Sem caixa aberto, nada muda neste fluxo.
            $this->cashRecorder->record($payment, $recordedBy);

            return $payment;
        });
    }

    private function createPayment(
        Invoice $invoice,
        User $recordedBy,
        PaymentMethod $method,
        float $amount,
        Carbon $paidAt,
        ?string $notes,
    ): Payment {
        return Payment::create([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->client_id,
            'gateway' => 'manual',
            'purpose' => PaymentPurpose::ADVANCE->value,
            'method' => $method->value,
            'amount' => $amount,
            'status' => PaymentStatus::PAID->value,
            'paid_at' => $paidAt,
            'metadata' => array_filter(
                ['notes' => $notes, 'recorded_by' => $recordedBy->id],
                fn (mixed $value): bool => $value !== null,
            ),
        ]);
    }

    /**
     * Guarda de categoria checada aqui, não só escondida na UI (contrato §3-bis.4) — só a
     * fatura de uma internação aceita adiantamento.
     */
    private function assertAllowed(Invoice $invoice): void
    {
        $appointment = $invoice->appointment;

        if ($appointment === null || ServiceCategory::tryFrom($appointment->type) !== ServiceCategory::HOSPITALIZATION) {
            throw AdvancePaymentNotAllowedException::forInvoice($invoice);
        }
    }
}
