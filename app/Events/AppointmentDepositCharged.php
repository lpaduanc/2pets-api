<?php

namespace App\Events;

use App\Models\Appointment;
use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sinal cobrado do tutor na confirmação do agendamento (Fase 6). `$payment` é `null`
 * quando a cobrança no gateway falhou (`AppointmentDepositService::createCharge`) — o
 * agendamento já fica `deposit_status = pending` mesmo assim, e o tutor ainda é
 * notificado (com o valor, sem o link de pagamento).
 */
class AppointmentDepositCharged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly ?Payment $payment,
    ) {}
}
