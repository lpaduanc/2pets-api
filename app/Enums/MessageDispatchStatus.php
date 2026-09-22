<?php

namespace App\Enums;

/**
 * Estado de um envio individual (`message_dispatches`) — contrato
 * `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. `blocked_by_consent`/
 * `blocked_by_optout` nunca são silenciosos: existem exatamente para auditar depois quem não
 * recebeu e por quê (critério de aceite explícito da spec).
 */
enum MessageDispatchStatus: string
{
    case QUEUED = 'queued';
    case SENT = 'sent';
    case FAILED = 'failed';
    case BLOCKED_BY_OPTOUT = 'blocked_by_optout';
    case BLOCKED_BY_CONSENT = 'blocked_by_consent';

    public function label(): string
    {
        return match ($this) {
            self::QUEUED => 'Na fila',
            self::SENT => 'Enviado',
            self::FAILED => 'Falhou',
            self::BLOCKED_BY_OPTOUT => 'Bloqueado (descadastro)',
            self::BLOCKED_BY_CONSENT => 'Bloqueado (sem consentimento)',
        };
    }
}
