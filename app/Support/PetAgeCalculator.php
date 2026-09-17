<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Cálculo de idade compartilhado por `PetResource` e `PetDuplicateCandidateResource` — extraído
 * para as duas nunca divergirem no arredondamento nem no texto do rótulo (ex.: "2 anos e 3
 * meses" vs "10 meses").
 */
final class PetAgeCalculator
{
    /** @return array{years: int, months: int, label: string} */
    public static function calculate(Carbon $birthDate): array
    {
        $now = now();
        $years = (int) floor($birthDate->diffInYears($now));
        $months = ((int) floor($birthDate->diffInMonths($now))) % 12;

        return [
            'years' => $years,
            'months' => $months,
            'label' => self::label($years, $months),
        ];
    }

    public static function label(int $years, int $months): string
    {
        if ($years > 0) {
            $yearsLabel = "{$years} ano".($years > 1 ? 's' : '');
            $monthsLabel = $months > 0 ? " e {$months} mes".($months > 1 ? 'es' : '') : '';

            return $yearsLabel.$monthsLabel;
        }

        return "{$months} mes".($months > 1 ? 'es' : '');
    }
}
