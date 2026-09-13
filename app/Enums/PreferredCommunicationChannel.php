<?php

namespace App\Enums;

/**
 * Canal de contato preferido da empresa parceira para tratar da negociação do benefício
 * (`CompleteProfileCompany.vue` — select `preferred_communication`, opcional).
 *
 * Não é `App\Enums\NotificationChannel`: aquele é o canal de envio de notificação do app
 * (e-mail/push/SMS/WhatsApp) para o usuário final; este é o canal que o time comercial do
 * 2pets usa para ligar de volta para o parceiro, inclui `meeting` (reunião presencial) e
 * não inclui push/SMS.
 */
enum PreferredCommunicationChannel: string
{
    case EMAIL = 'email';
    case PHONE = 'phone';
    case WHATSAPP = 'whatsapp';
    case MEETING = 'meeting';

    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'Email',
            self::PHONE => 'Telefone',
            self::WHATSAPP => 'WhatsApp',
            self::MEETING => 'Reunião presencial',
        };
    }
}
