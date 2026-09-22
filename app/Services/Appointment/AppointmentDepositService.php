<?php

namespace App\Services\Appointment;

use App\Contracts\PaymentGatewayInterface;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Events\AppointmentDepositCharged;
use App\Events\AppointmentDepositPaid;
use App\Models\Appointment;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Support\Facades\Log;

/**
 * Fase 6 do fluxo de agendamento: cobra o sinal (se aplicável) quando o profissional
 * confirma um agendamento, e resolve o pagamento quando o webhook do gateway avisa que foi
 * pago. Nunca cria fatura — sinal é cobrado ANTES de qualquer `Invoice` existir (ela só
 * nasce no início do atendimento, `ConsultationController::start`).
 */
final class AppointmentDepositService
{
    public function __construct(
        private readonly DepositConfigResolver $configResolver,
        private readonly PaymentGatewayInterface $gateway,
    ) {}

    /**
     * Chamado por `AppointmentConfirmationService::confirm()`. Sem sinal aplicável, não
     * toca em NADA do agendamento — `deposit_status` continua `none`, o valor do critério
     * de aceite explícito do dono do produto ("o caminho sem sinal não pode regredir").
     */
    public function chargeIfApplicable(Appointment $appointment): void
    {
        $service = $appointment->service;

        if ($service === null) {
            return;
        }

        $percentage = $this->configResolver->resolvePercentageFor($service);

        if ($percentage === null) {
            return;
        }

        $amount = $this->calculateAmount($service, $percentage);

        if ($amount <= 0.0) {
            return;
        }

        $appointment->update([
            'deposit_amount' => $amount,
            'deposit_status' => DepositStatus::PENDING->value,
        ]);

        $payment = $this->createCharge($appointment, $amount);

        AppointmentDepositCharged::dispatch($appointment->fresh(), $payment);
    }

    /**
     * Mesma convenção de dinheiro já usada pelo projeto (`InvoiceTotalsCalculator::
     * roundMoney()`): `round()` do PHP, 2 casas — nunca float solto sem arredondar antes de
     * gravar em coluna `decimal:2`.
     */
    private function calculateAmount(Service $service, float $percentage): float
    {
        return round(((float) $service->price) * $percentage / 100, 2);
    }

    /**
     * `null` quando o gateway falha (sem credencial configurada, rede fora, etc.) — o
     * agendamento já ficou `pending` antes desta chamada, então o tutor ainda vê "sinal
     * pendente" e o profissional ainda pode cancelar manualmente; só não há um link de
     * pagamento pronto. Nenhuma linha de `Payment` "fantasma" é criada para uma tentativa
     * que nunca existiu no gateway (sem um `gateway_payment_id` real não há o que
     * reconciliar depois).
     */
    private function createCharge(Appointment $appointment, float $amount): ?Payment
    {
        $client = $appointment->client;

        if ($client === null) {
            return null;
        }

        try {
            $result = $this->gateway->createPayment(
                $amount,
                PaymentMethod::PIX,
                [
                    'email' => $client->email,
                    'name' => $client->name,
                    'document' => $client->cpf ?? $client->cnpj,
                ],
                1,
                [
                    'description' => "Sinal do agendamento #{$appointment->id}",
                    'external_reference' => "appointment-deposit-{$appointment->id}",
                ],
            );
        } catch (\Throwable $exception) {
            Log::error('Falha ao criar cobrança de sinal', [
                'appointment_id' => $appointment->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! ($result['success'] ?? false)) {
            Log::warning('Gateway recusou a cobrança de sinal', [
                'appointment_id' => $appointment->id,
                'error' => $result['error'] ?? 'unknown',
            ]);

            return null;
        }

        return Payment::create([
            'appointment_id' => $appointment->id,
            'user_id' => $client->id,
            'gateway' => 'mercadopago',
            'gateway_payment_id' => $result['payment_id'],
            'purpose' => PaymentPurpose::DEPOSIT->value,
            'method' => PaymentMethod::PIX->value,
            'amount' => $amount,
            'status' => $result['status'],
            'metadata' => [
                'qr_code' => $result['qr_code'] ?? null,
                'qr_code_base64' => $result['qr_code_base64'] ?? null,
                'ticket_url' => $result['ticket_url'] ?? null,
            ],
        ]);
    }

    /**
     * Chamado pelo job de webhook (`ProcessPaymentWebhook`) quando `$payment->purpose` é
     * `deposit` — nunca passa por `PaymentService::updatePaymentStatus()` (aquele método
     * assume fatura, e sinal não tem).
     */
    public function syncStatusFromGateway(Payment $payment): void
    {
        $status = $this->gateway->getPaymentStatus($payment->gateway_payment_id);
        $payment->update(['status' => $status]);

        if ($status !== PaymentStatus::PAID->value) {
            return;
        }

        $payment->update(['paid_at' => $payment->paid_at ?? now()]);

        $appointment = $payment->appointment;

        if ($appointment === null || $appointment->deposit_status === DepositStatus::PAID->value) {
            return;
        }

        $appointment->update(['deposit_status' => DepositStatus::PAID->value]);

        AppointmentDepositPaid::dispatch($appointment->fresh(), $payment);
    }
}
