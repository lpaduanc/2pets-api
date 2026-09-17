<?php

namespace App\Enums;

/**
 * Tipo de receituário — slugs FIXADOS por docs/atendimento-veterinario/03-contrato-receituario.md
 * §4. Só `SIMPLE` tem fluxo operacional nesta fatia: `SPECIAL_CONTROL` e `ANTIMICROBIAL` são
 * rótulo informativo (doc de domínio §4.1) — não geram numeração, talão nem integração SNCR.
 */
enum PrescriptionKind: string
{
    case SIMPLE = 'simple';
    case SPECIAL_CONTROL = 'special_control';
    case ANTIMICROBIAL = 'antimicrobial';

    public function label(): string
    {
        return match ($this) {
            self::SIMPLE => 'Simples',
            self::SPECIAL_CONTROL => 'Controle especial',
            self::ANTIMICROBIAL => 'Antimicrobiano',
        };
    }

    /**
     * Res. 344/98 (alt. RDC Anvisa 999/2025 e 1000) exige talão físico até a integração ao
     * SNCR (prevista para 30/09/2026) — o app NUNCA substitui esse papel para este tipo.
     */
    public function requiresPhysicalCounterpart(): bool
    {
        return $this === self::SPECIAL_CONTROL;
    }
}
