<?php

namespace App\Enums;

/** Contrato docs/gap-simplesvet/specs/16-modelos-exame-laudos-spec.md. */
enum ExamTypeCategory: string
{
    case LABORATORY = 'laboratory';
    case IMAGING = 'imaging';
    case CYTOLOGY = 'cytology';
    case HISTOPATHOLOGY = 'histopathology';
    case CARDIOLOGY = 'cardiology';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::LABORATORY => 'Laboratorial',
            self::IMAGING => 'Imagem',
            self::CYTOLOGY => 'Citologia',
            self::HISTOPATHOLOGY => 'Histopatologia',
            self::CARDIOLOGY => 'Cardiologia',
            self::OTHER => 'Outro',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
