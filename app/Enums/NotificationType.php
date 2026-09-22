<?php

namespace App\Enums;

enum NotificationType: string
{
    case APPOINTMENT_REMINDER_24H = 'appointment_reminder_24h';
    case APPOINTMENT_REMINDER_2H = 'appointment_reminder_2h';
    // Fase 4 do fluxo de agendamento: o profissional recebe isto quando o TUTOR pede um
    // horário — distinto de `APPOINTMENT_CONFIRMED`, que é o tutor sendo avisado de que o
    // profissional aceitou. Antes desta fase, `SendAppointmentNotification::handleBooked()`
    // reusava `APPOINTMENT_CONFIRMED` por engano para o aviso ao profissional.
    case APPOINTMENT_REQUESTED = 'appointment_requested';
    case APPOINTMENT_CONFIRMED = 'appointment_confirmed';
    case APPOINTMENT_REJECTED = 'appointment_rejected';
    case APPOINTMENT_CANCELLED = 'appointment_cancelled';
    case APPOINTMENT_RESCHEDULED = 'appointment_rescheduled';
    // Fase 6 do fluxo de agendamento — sinal (pagamento parcial antecipado).
    case APPOINTMENT_DEPOSIT_REQUESTED = 'appointment_deposit_requested';
    case APPOINTMENT_DEPOSIT_PAID = 'appointment_deposit_paid';
    case VACCINATION_DUE = 'vaccination_due';
    case MEDICATION_REMINDER = 'medication_reminder';
    case PAYMENT_RECEIVED = 'payment_received';
    case PAYMENT_FAILED = 'payment_failed';
    case NEW_MESSAGE = 'new_message';
    case REVIEW_REQUEST = 'review_request';
    case WAITLIST_AVAILABLE = 'waitlist_available';
    case LOST_PET_ALERT_NEARBY = 'lost_pet_alert_nearby';
    // Orçamento (docs/gap-simplesvet/24-orcamentos.md): o tutor recebe, a clínica é avisada
    // da decisão.
    case QUOTE_RECEIVED = 'quote_received';
    case QUOTE_APPROVED = 'quote_approved';
    case QUOTE_REJECTED = 'quote_rejected';

    public function label(): string
    {
        return match ($this) {
            self::APPOINTMENT_REMINDER_24H => 'Lembrete de consulta (24h)',
            self::APPOINTMENT_REMINDER_2H => 'Lembrete de consulta (2h)',
            self::APPOINTMENT_REQUESTED => 'Novo pedido de agendamento',
            self::APPOINTMENT_CONFIRMED => 'Consulta confirmada',
            self::APPOINTMENT_REJECTED => 'Agendamento recusado',
            self::APPOINTMENT_CANCELLED => 'Consulta cancelada',
            self::APPOINTMENT_RESCHEDULED => 'Consulta reagendada',
            self::APPOINTMENT_DEPOSIT_REQUESTED => 'Sinal do agendamento',
            self::APPOINTMENT_DEPOSIT_PAID => 'Sinal recebido',
            self::VACCINATION_DUE => 'Vacinação em atraso',
            self::MEDICATION_REMINDER => 'Lembrete de medicação',
            self::PAYMENT_RECEIVED => 'Pagamento recebido',
            self::PAYMENT_FAILED => 'Falha no pagamento',
            self::NEW_MESSAGE => 'Nova mensagem',
            self::REVIEW_REQUEST => 'Solicitação de avaliação',
            self::WAITLIST_AVAILABLE => 'Horário disponível',
            self::LOST_PET_ALERT_NEARBY => 'Pet perdido na sua região',
            self::QUOTE_RECEIVED => 'Orçamento recebido',
            self::QUOTE_APPROVED => 'Orçamento aprovado',
            self::QUOTE_REJECTED => 'Orçamento recusado',
        };
    }
}
