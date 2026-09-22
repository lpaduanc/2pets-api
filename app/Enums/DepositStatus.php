<?php

namespace App\Enums;

/**
 * Estado do sinal (pagamento parcial antecipado) de um `Appointment` — Fase 6 do fluxo de
 * agendamento. `NONE` é o padrão de TODO agendamento: sinal é opcional e desligado por
 * padrão (decisão do dono do produto), então a imensa maioria dos agendamentos nunca sai
 * de `none`.
 *
 * Sem cancelamento automático por sinal não pago: `PENDING` fica pendurado até o
 * profissional decidir cancelar manualmente (o cancelamento que já existe,
 * `AppointmentConfirmationController::reject`/`BookingController::cancel`) — nenhum job de
 * expiração, por decisão explícita do dono do produto.
 */
enum DepositStatus: string
{
    case NONE = 'none';
    case PENDING = 'pending';
    case PAID = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::NONE => 'Sem sinal',
            self::PENDING => 'Sinal pendente',
            self::PAID => 'Sinal pago',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
