<?php

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentChannel;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Exceptions\Invoice\CreditResolutionRequiredException;
use App\Exceptions\Invoice\InconsistentInvoicePaymentStateException;
use App\Exceptions\Invoice\InvalidInvoiceStatusTransitionException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Commercial\InvoicePaymentCashRecorder;
use App\Services\Finance\InvoiceFinancialEntryRecorder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly InvoicePaymentCashRecorder $cashRecorder,
        private readonly InvoiceFinancialEntryRecorder $financialEntries,
    ) {}

    public function createPayment(
        Invoice $invoice,
        User $user,
        PaymentMethod $method,
        int $installments = 1
    ): Payment {
        return DB::transaction(function () use ($invoice, $user, $method, $installments) {
            $payerData = [
                'email' => $user->email,
                'name' => $user->name,
                'document' => $user->cpf ?? $user->cnpj,
            ];

            $metadata = [
                'description' => "Pagamento Fatura #{$invoice->id}",
                'external_reference' => "invoice-{$invoice->id}",
            ];

            $result = $this->gateway->createPayment(
                // A coluna da tabela `invoices` é `total`; `total_amount` não existe e chegava
                // como null no gateway (TypeError em createPayment(float $amount)).
                (float) $invoice->total,
                $method,
                $payerData,
                $installments,
                $metadata
            );

            if (! $result['success']) {
                throw new \Exception($result['error'] ?? 'Payment creation failed');
            }

            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'user_id' => $user->id,
                'gateway' => 'mercadopago',
                'gateway_payment_id' => $result['payment_id'],
                'method' => $method->value,
                'amount' => $invoice->total,
                'status' => $result['status'],
                'installments' => $installments,
                'gateway_response' => $result['response'] ?? null,
                'metadata' => [
                    'qr_code' => $result['qr_code'] ?? null,
                    'qr_code_base64' => $result['qr_code_base64'] ?? null,
                    'ticket_url' => $result['ticket_url'] ?? null,
                ],
            ]);

            if ($payment->status === PaymentStatus::PAID->value) {
                $this->markInvoiceAsPaid($invoice, $payment, PaymentChannel::PLATFORM_GATEWAY);
            }

            return $payment;
        });
    }

    public function updatePaymentStatus(Payment $payment): void
    {
        $status = $this->gateway->getPaymentStatus($payment->gateway_payment_id);

        $payment->update(['status' => $status]);

        if ($status === PaymentStatus::PAID->value && ! $payment->paid_at) {
            $payment->update(['paid_at' => now()]);
            $this->markInvoiceAsPaid($payment->invoice, $payment, PaymentChannel::PLATFORM_GATEWAY);
        }
    }

    public function refundPayment(Payment $payment, ?float $amount = null): bool
    {
        if ($payment->status !== PaymentStatus::PAID->value) {
            throw new \Exception('Only paid payments can be refunded');
        }

        $success = $this->gateway->refundPayment(
            $payment->gateway_payment_id,
            $amount
        );

        if ($success) {
            $payment->update(['status' => PaymentStatus::REFUNDED->value]);
            $this->assertTransition($payment->invoice, InvoiceStatus::REFUNDED);
            $payment->invoice->update(['status' => InvoiceStatus::REFUNDED->value]);
        }

        return $success;
    }

    /**
     * Único ponto de escrita de "fatura foi paga" — reaproveitado pelo webhook do gateway
     * (`createPayment`/`updatePaymentStatus`) e pelo `mark-as-paid` manual do balcão
     * (`markInvoiceAsPaidManually`). Contrato
     * docs/atendimento-veterinario/09-faturamento-do-atendimento.md §6/§12.1: a coluna real é
     * `payment_date` (não `paid_at`, que não existe em `invoices`), e `payment_channel` é
     * sempre gravado explicitamente — nunca inferido.
     */
    public function markInvoiceAsPaid(Invoice $invoice, Payment $payment, PaymentChannel $channel): Invoice
    {
        $this->assertTransition($invoice, InvoiceStatus::PAID);

        $invoice->update([
            'status' => InvoiceStatus::PAID->value,
            'payment_date' => ($payment->paid_at ?? now())->toDateString(),
            'payment_method' => $payment->method,
            'payment_channel' => $channel->value,
        ]);

        // Perna contábil da fatura (doc 02): receita no DRE, independente do canal
        // (gateway ou balcão) — este é o único ponto de escrita de "fatura foi paga".
        $this->financialEntries->recordForPaidInvoice($invoice, $payment);

        return $invoice;
    }

    /**
     * `POST /invoices/{id}/mark-as-paid` — declaração auditável do profissional (dinheiro,
     * PIX direto), nunca autodeclarada pelo tutor (`InvoicePolicy::receivePayment` já
     * garantiu isso antes de chegar aqui). Reaproveita o `Payment` vinculado existente em vez
     * de criar outro (ver `reuseOrCreatePayment`) e delega a escrita da fatura ao mesmo
     * método usado pelo caminho de gateway.
     *
     * Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md
     * §3-bis.3: o valor do acerto final é `balanceDue()`, não `Invoice.total` — para fatura
     * sem adiantamento os dois são idênticos (comportamento de hoje, sem mudança); para
     * internação com adiantamento, é o que falta depois do que já foi recebido. Sobra
     * (`creditBalance() > 0`) exige `credit_resolution` declarado — nunca devolução
     * automática (o 2pets não custodiou o adiantamento, ele é sempre `manual_offline`).
     */
    public function markInvoiceAsPaidManually(
        Invoice $invoice,
        User $recordedBy,
        PaymentMethod $method,
        Carbon $paidAt,
        ?string $notes,
        ?string $creditResolution = null,
    ): Invoice {
        return DB::transaction(function () use ($invoice, $recordedBy, $method, $paidAt, $notes, $creditResolution): Invoice {
            $this->assertCreditResolutionProvidedIfNeeded($invoice, $creditResolution);

            $payment = $this->reuseOrCreatePayment($invoice, [
                'gateway' => 'manual',
                'purpose' => PaymentPurpose::SETTLEMENT->value,
                'method' => $method->value,
                'amount' => $invoice->balanceDue(),
                'status' => PaymentStatus::PAID->value,
                'paid_at' => $paidAt,
                'metadata' => array_filter([
                    'notes' => $notes,
                    'recorded_by' => $recordedBy->id,
                    'credit_resolution' => $creditResolution,
                ], fn (mixed $value): bool => $value !== null),
            ]);

            $invoice = $this->markInvoiceAsPaid($invoice, $payment, PaymentChannel::MANUAL_OFFLINE);

            // Recebimento no balcão entra no caixa aberto de quem recebeu (doc
            // gap-simplesvet/01). Sem caixa aberto, nada muda neste fluxo.
            $this->cashRecorder->record($payment, $recordedBy);

            return $invoice;
        });
    }

    private function assertCreditResolutionProvidedIfNeeded(Invoice $invoice, ?string $creditResolution): void
    {
        if ($invoice->creditBalance() > 0.0 && $creditResolution === null) {
            throw CreditResolutionRequiredException::forInvoice($invoice);
        }
    }

    /**
     * Reaproveita o `Payment` de ACERTO FINAL (`purpose = settlement`) NÃO finalizado já
     * vinculado à fatura (ex.: uma tentativa de PIX/gateway que ficou
     * `pending`/`processing`, ou `failed`) em vez de criar uma segunda linha para o mesmo
     * lançamento — era isso que duplicava `Payment` a cada `mark-as-paid`. Um adiantamento
     * (`purpose = advance`) nunca é candidato: é sempre um evento próprio, nunca uma
     * tentativa do MESMO lançamento que o acerto final está fechando. Quando há mais de um
     * candidato, reaproveita o mais recente (`created_at` desc). Ver
     * `InconsistentInvoicePaymentStateException` para o caso de já existir um acerto final
     * `paid`.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function reuseOrCreatePayment(Invoice $invoice, array $attributes): Payment
    {
        if ($this->hasPaidPayment($invoice)) {
            throw InconsistentInvoicePaymentStateException::alreadyHasPaidPayment($invoice);
        }

        $reusable = $this->findReusablePayment($invoice);

        if ($reusable !== null) {
            $reusable->update($attributes);

            return $reusable;
        }

        return Payment::create([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->client_id,
            ...$attributes,
        ]);
    }

    /**
     * Contrato §3-bis.2: a trava de duplicidade passa a checar "já existe um ACERTO FINAL
     * pago", não mais "qualquer `Payment` pago" — um adiantamento pago não bloqueia o
     * acerto final que vem depois dele. Para fatura sem adiantamento (100% dos casos fora
     * de internação), as duas perguntas têm sempre a mesma resposta.
     */
    private function hasPaidPayment(Invoice $invoice): bool
    {
        return Payment::where('invoice_id', $invoice->id)
            ->where('status', PaymentStatus::PAID->value)
            ->where('purpose', PaymentPurpose::SETTLEMENT->value)
            ->exists();
    }

    private function findReusablePayment(Invoice $invoice): ?Payment
    {
        return Payment::where('invoice_id', $invoice->id)
            ->where('purpose', PaymentPurpose::SETTLEMENT->value)
            ->whereNotIn('status', [PaymentStatus::PAID->value, PaymentStatus::REFUNDED->value])
            ->orderByDesc('created_at')
            ->first();
    }

    private function assertTransition(Invoice $invoice, InvoiceStatus $target): void
    {
        $current = InvoiceStatus::from($invoice->status);

        if (! $current->canTransitionTo($target)) {
            throw InvalidInvoiceStatusTransitionException::notAllowed($current, $target);
        }
    }
}
