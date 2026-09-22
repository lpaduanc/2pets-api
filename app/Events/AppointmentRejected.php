<?php

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Recusa EXPLÍCITA do profissional (`POST professional/appointments/{id}/reject`, Fase 4)
 * — semanticamente distinta de `AppointmentCancelled` (cancelamento do tutor ou
 * administrativo) mesmo que as duas gravem `status = cancelled` por baixo (não existe
 * status próprio de "recusado" na CHECK constraint de `appointments.status`, e criar um
 * exigiria migração de schema fora do escopo desta fase). A notificação do tutor precisa
 * dizer "recusado", não "cancelado" — daí o evento próprio.
 */
class AppointmentRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly ?string $reason,
    ) {}
}
