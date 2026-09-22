<?php

namespace App\Enums;

/**
 * Ciclo de vida de uma campanha (`message_campaigns`) — contrato
 * `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`.
 */
enum MessageCampaignStatus: string
{
    case DRAFT = 'draft';
    case SCHEDULED = 'scheduled';
    case SENDING = 'sending';
    case SENT = 'sent';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::SCHEDULED => 'Agendada',
            self::SENDING => 'Enviando',
            self::SENT => 'Enviada',
            self::CANCELLED => 'Cancelada',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::SENT, self::CANCELLED], true);
    }
}
