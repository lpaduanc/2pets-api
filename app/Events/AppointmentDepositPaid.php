<?php

namespace App\Events;

use App\Models\Appointment;
use App\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Webhook do gateway confirmou o pagamento do sinal (Fase 6). */
class AppointmentDepositPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly Payment $payment,
    ) {}
}
