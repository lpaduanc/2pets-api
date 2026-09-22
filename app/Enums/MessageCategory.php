<?php

namespace App\Enums;

/**
 * Distingue mensagem OPERACIONAL (nunca depende de opt-out de marketing) de CAMPANHA
 * promocional (exige `consent_*_marketing` do canal) — contrato
 * `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`, regra de negócio 2.
 */
enum MessageCategory: string
{
    case TRANSACTIONAL = 'transactional';
    case MARKETING = 'marketing';

    public function label(): string
    {
        return match ($this) {
            self::TRANSACTIONAL => 'Transacional',
            self::MARKETING => 'Marketing',
        };
    }
}
