<?php

namespace App\Enums;

enum NotificationChannel: string
{
    case EMAIL = 'email';
    case PUSH = 'push';
    case SMS = 'sms';
    case WHATSAPP = 'whatsapp';

    public function label(): string
    {
        return match ($this) {
            self::EMAIL => 'E-mail',
            self::PUSH => 'Notificação Push',
            self::SMS => 'SMS',
            self::WHATSAPP => 'WhatsApp',
        };
    }
}

