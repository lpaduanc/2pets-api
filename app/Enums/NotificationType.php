<?php

namespace App\Enums;

enum NotificationType: string
{
    case APPOINTMENT_REMINDER_24H = 'appointment_reminder_24h';
    case APPOINTMENT_REMINDER_2H = 'appointment_reminder_2h';
    case APPOINTMENT_CONFIRMED = 'appointment_confirmed';
    case APPOINTMENT_CANCELLED = 'appointment_cancelled';
    case APPOINTMENT_RESCHEDULED = 'appointment_rescheduled';
    case VACCINATION_DUE = 'vaccination_due';
    case MEDICATION_REMINDER = 'medication_reminder';
    case PAYMENT_RECEIVED = 'payment_received';
    case PAYMENT_FAILED = 'payment_failed';
    case NEW_MESSAGE = 'new_message';
    case REVIEW_REQUEST = 'review_request';
    case WAITLIST_AVAILABLE = 'waitlist_available';

    public function label(): string
    {
        return match ($this) {
            self::APPOINTMENT_REMINDER_24H => 'Lembrete de consulta (24h)',
            self::APPOINTMENT_REMINDER_2H => 'Lembrete de consulta (2h)',
            self::APPOINTMENT_CONFIRMED => 'Consulta confirmada',
            self::APPOINTMENT_CANCELLED => 'Consulta cancelada',
            self::APPOINTMENT_RESCHEDULED => 'Consulta reagendada',
            self::VACCINATION_DUE => 'Vacinação em atraso',
            self::MEDICATION_REMINDER => 'Lembrete de medicação',
            self::PAYMENT_RECEIVED => 'Pagamento recebido',
            self::PAYMENT_FAILED => 'Falha no pagamento',
            self::NEW_MESSAGE => 'Nova mensagem',
            self::REVIEW_REQUEST => 'Solicitação de avaliação',
            self::WAITLIST_AVAILABLE => 'Horário disponível',
        };
    }
}

