<?php

namespace App\Enums;

/**
 * Estado do ciclo de vida de uma consulta (`appointments.status`).
 *
 * Não é usado como `casts()` do model: várias leituras fora do domínio Appointment
 * (`DashboardController`, `ReviewService`, `SendScheduledNotifications`) comparam o valor como
 * string crua hoje, e não são deste agente para migrar em conjunto. Este enum é, ainda assim, a
 * fonte única da MÁQUINA DE ESTADOS — toda escrita de status que precisa validar transição passa
 * por `AppointmentStatusTransitionService`, nunca por `if` solto em controller.
 *
 * `pending` só é gravado por `BookingService` (fluxo de agendamento pelo tutor, aguardando
 * confirmação do profissional — `POST /api/booking`). O endpoint do profissional
 * (`PUT /api/professional/appointments/{id}`) nunca recebe `pending` como valor de entrada
 * (ver `UpdateAppointmentRequest`), mas pode partir de um agendamento que já nasceu `pending`.
 */
enum AppointmentStatus: string
{
    case PENDING = 'pending';
    case SCHEDULED = 'scheduled';
    case CONFIRMED = 'confirmed';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case NO_SHOW = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Aguardando confirmação',
            self::SCHEDULED => 'Agendado',
            self::CONFIRMED => 'Confirmado',
            self::IN_PROGRESS => 'Em andamento',
            self::COMPLETED => 'Concluído',
            self::CANCELLED => 'Cancelado',
            self::NO_SHOW => 'Não compareceu',
        };
    }

    /**
     * Estados alcançáveis a partir deste pelo fluxo normal do profissional.
     *
     * `completed`, `cancelled` e `no_show` são terminais: nenhum fluxo do produto hoje reabre
     * uma consulta encerrada (`BookingService::rescheduleBooking` inclusive recusa reagendar uma
     * consulta `cancelled` ou `completed`). Se o produto precisar de uma correção administrativa
     * de lançamento, é uma ação nova e explícita — não a mesma porta do PUT do profissional.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::SCHEDULED, self::CONFIRMED, self::CANCELLED, self::NO_SHOW],
            self::SCHEDULED => [self::CONFIRMED, self::CANCELLED, self::NO_SHOW],
            self::CONFIRMED => [self::IN_PROGRESS, self::CANCELLED, self::NO_SHOW],
            self::IN_PROGRESS => [self::COMPLETED, self::CANCELLED],
            self::COMPLETED, self::CANCELLED, self::NO_SHOW => [],
        };
    }

    /** Reenviar o mesmo status (PUT idempotente) nunca é uma transição inválida. */
    public function canTransitionTo(self $target): bool
    {
        return $this === $target || in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
