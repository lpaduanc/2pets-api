<?php

namespace App\Enums;

enum ExamResultStatus: string
{
    case NORMAL = 'normal';
    case HIGH = 'high';
    case LOW = 'low';
    case CRITICAL = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::NORMAL => 'Normal',
            self::HIGH => 'Alto',
            self::LOW => 'Baixo',
            self::CRITICAL => 'Crítico',
        };
    }

    public function isAltered(): bool
    {
        return $this !== self::NORMAL;
    }

    /**
     * Deriva o status comparando o valor numérico do resultado com a faixa de referência.
     * Nunca devolve CRITICAL — não existe limiar de criticidade nos dados do exame, só o
     * operador marca isso manualmente (ver `ExamService::resolveStatus`, que dá prioridade
     * a um status informado explicitamente sobre este cálculo).
     */
    public static function deriveFrom(?float $value, ?float $referenceMin, ?float $referenceMax): ?self
    {
        if ($value === null || $referenceMin === null || $referenceMax === null) {
            return null;
        }

        return match (true) {
            $value < $referenceMin => self::LOW,
            $value > $referenceMax => self::HIGH,
            default => self::NORMAL,
        };
    }
}
