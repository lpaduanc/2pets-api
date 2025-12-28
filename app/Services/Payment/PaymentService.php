<?php

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway
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
                $invoice->total_amount,
                $method,
                $payerData,
                $installments,
                $metadata
            );

            if (!$result['success']) {
                throw new \Exception($result['error'] ?? 'Payment creation failed');
            }

            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'user_id' => $user->id,
                'gateway' => 'mercadopago',
                'gateway_payment_id' => $result['payment_id'],
                'method' => $method->value,
                'amount' => $invoice->total_amount,
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
                $this->markInvoiceAsPaid($invoice, $payment);
            }

            return $payment;
        });
    }

    public function updatePaymentStatus(Payment $payment): void
    {
        $status = $this->gateway->getPaymentStatus($payment->gateway_payment_id);

        $payment->update(['status' => $status]);

        if ($status === PaymentStatus::PAID->value && !$payment->paid_at) {
            $payment->update(['paid_at' => now()]);
            $this->markInvoiceAsPaid($payment->invoice, $payment);
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

            // Update invoice status
            $payment->invoice->update(['status' => 'refunded']);
        }

        return $success;
    }

    private function markInvoiceAsPaid(Invoice $invoice, Payment $payment): void
    {
        $invoice->update([
            'status' => 'paid',
            'paid_at' => $payment->paid_at ?? now(),
        ]);

        // TODO: Dispatch PaymentReceived event for notifications
    }
}

