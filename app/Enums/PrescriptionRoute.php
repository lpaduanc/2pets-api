<?php

namespace App\Enums;

/**
 * Via de administração do item de prescrição — slugs FIXADOS por
 * docs/atendimento-veterinario/03-contrato-receituario.md §4. Backend e frontend não
 * divergem destes valores; o frontend guarda os próprios rótulos.
 *
 * `label()` existe para o texto server-renderizado (PDF, lembrete) — não é o rótulo que a
 * tela do profissional usa, que vive em `pet-options.js` do frontend.
 */
enum PrescriptionRoute: string
{
    case ORAL = 'oral';
    case SUBCUTANEOUS = 'subcutaneous';
    case INTRAMUSCULAR = 'intramuscular';
    case INTRAVENOUS = 'intravenous';
    case TOPICAL = 'topical';
    case OPHTHALMIC = 'ophthalmic';
    case OTIC = 'otic';
    case RECTAL = 'rectal';
    case INHALATION = 'inhalation';
    case TRANSDERMAL = 'transdermal';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::ORAL => 'Oral',
            self::SUBCUTANEOUS => 'Subcutânea (SC)',
            self::INTRAMUSCULAR => 'Intramuscular (IM)',
            self::INTRAVENOUS => 'Intravenosa (IV)',
            self::TOPICAL => 'Tópica',
            self::OPHTHALMIC => 'Oftálmica',
            self::OTIC => 'Otológica',
            self::RECTAL => 'Retal',
            self::INHALATION => 'Inalatória',
            self::TRANSDERMAL => 'Transdérmica',
            self::OTHER => 'Outra',
        };
    }
}
