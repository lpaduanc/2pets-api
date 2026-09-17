<?php

namespace App\Enums;

/**
 * Frequência de administração do item de prescrição — slugs FIXADOS por
 * docs/atendimento-veterinario/03-contrato-receituario.md §4.
 *
 * As siglas SID/BID/TID/QID são vocabulário real de mercado veterinário; o contrato exige
 * que o rótulo sempre venha COM a tradução ao lado ("BID — 2x ao dia"), nunca só a sigla.
 */
enum PrescriptionFrequency: string
{
    case SID = 'sid';
    case BID = 'bid';
    case TID = 'tid';
    case QID = 'qid';
    case EOD = 'eod';
    case EVERY_X_HOURS = 'every_x_hours';
    case SINGLE_DOSE = 'single_dose';
    case AS_NEEDED = 'as_needed';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::SID => 'SID — 1x ao dia',
            self::BID => 'BID — 2x ao dia',
            self::TID => 'TID — 3x ao dia',
            self::QID => 'QID — 4x ao dia',
            self::EOD => 'Dia sim, dia não',
            self::EVERY_X_HOURS => 'A cada X horas',
            self::SINGLE_DOSE => 'Dose única',
            self::AS_NEEDED => 'Conforme necessário (SOS)',
            self::OTHER => 'Outra',
        };
    }

    /**
     * Intervalo em horas entre doses — usado só para calcular o cronograma de lembretes
     * (`PrescriptionReminderScheduler`). `null` significa "sem cronograma previsível": dose
     * única, SOS ou frequência livre não geram mais de um lembrete.
     */
    public function intervalHours(?int $customHours = null): ?int
    {
        return match ($this) {
            self::SID => 24,
            self::BID => 12,
            self::TID => 8,
            self::QID => 6,
            self::EOD => 48,
            self::EVERY_X_HOURS => $customHours,
            self::SINGLE_DOSE, self::AS_NEEDED, self::OTHER => null,
        };
    }
}
