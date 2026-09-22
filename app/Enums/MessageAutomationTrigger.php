<?php

namespace App\Enums;

/**
 * Gatilhos de automação de CRM — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`.
 * `invoice_overdue`/`package_expiring`/`exam_ready`/`hospitalization_discharge` ficam de fora
 * desta entrega (dependem dos docs 03/10/12, V2) — o enum cresce depois, sem mudança de schema.
 */
enum MessageAutomationTrigger: string
{
    case VACCINE_DUE = 'vaccine_due';
    case VACCINE_OVERDUE = 'vaccine_overdue';
    case DEWORMING_DUE = 'deworming_due';
    case BIRTHDAY_PET = 'birthday_pet';
    case BIRTHDAY_CLIENT = 'birthday_client';
    case POST_APPOINTMENT_FOLLOWUP = 'post_appointment_followup';
    case INACTIVE_CLIENT = 'inactive_client';

    public function label(): string
    {
        return match ($this) {
            self::VACCINE_DUE => 'Vacina a vencer',
            self::VACCINE_OVERDUE => 'Vacina vencida',
            self::DEWORMING_DUE => 'Vermífugo a vencer',
            self::BIRTHDAY_PET => 'Aniversário do pet',
            self::BIRTHDAY_CLIENT => 'Aniversário do tutor',
            self::POST_APPOINTMENT_FOLLOWUP => 'Pós-atendimento',
            self::INACTIVE_CLIENT => 'Cliente inativo',
        };
    }
}
