<?php

namespace App\Services\Commercial;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethodKind;
use App\Enums\PaymentPurpose;
use App\Models\Payment;
use App\Models\User;

/**
 * Leva para o caixa o dinheiro que entra pelos fluxos que JÁ EXISTIAM antes do PDV — contrato
 * docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * A recepção recebe no balcão não só a venda de balcão (`sales`), mas também a conta do
 * atendimento (`invoices`, `POST invoices/{id}/mark-as-paid`) e o adiantamento da internação
 * (`POST invoices/{id}/advance-payment`). Se esses recebimentos ficassem fora do caixa, a
 * conferência do fim do dia acusaria sobra em toda gaveta que recebeu uma consulta em dinheiro.
 *
 * Regras:
 *  - só entra no caixa ABERTO de quem registrou o recebimento. Sem caixa aberto, o fluxo da
 *    fatura segue exatamente como antes (o vet volante que não usa caixa não é obrigado a
 *    abrir um para dar baixa numa consulta);
 *  - o movimento referencia o `Payment` (polimórfico), para ir da gaveta até a fatura;
 *  - a forma do marketplace (`cash`, `pix`...) é traduzida para a forma CADASTRADA da clínica
 *    de mesma natureza, para cair na coluna certa da conferência cega.
 */
final class InvoicePaymentCashRecorder
{
    public function __construct(
        private readonly CashRegisterService $cashRegisters,
        private readonly PaymentMethodProvisioner $paymentMethods,
    ) {}

    public function record(Payment $payment, User $receivedBy): void
    {
        $register = $this->cashRegisters->currentFor($receivedBy);

        if ($register === null || (float) $payment->amount <= 0) {
            return;
        }

        $kind = PaymentMethodKind::tryFrom((string) $payment->method);
        $method = $kind === null ? null : $this->paymentMethods->methodForKind($receivedBy, $kind);

        $this->cashRegisters->recordMovement(
            $register,
            CashMovementType::SALE_RECEIPT,
            (float) $payment->amount,
            $this->describe($payment),
            $receivedBy,
            $method?->id,
            $method?->default_account_id,
            $payment,
            $payment->paid_at,
        );
    }

    private function describe(Payment $payment): string
    {
        $invoiceNumber = $payment->invoice?->invoice_number ?? $payment->invoice_id;

        return $payment->purpose === PaymentPurpose::ADVANCE->value
            ? 'Adiantamento da conta '.$invoiceNumber
            : 'Recebimento da conta '.$invoiceNumber;
    }
}
