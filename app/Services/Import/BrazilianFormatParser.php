<?php

namespace App\Services\Import;

/**
 * Normalização de formatos brasileiros comuns em planilha exportada de sistema legado —
 * item 26 do backlog gap-simplesvet. Usado por `ClientImportValidator` hoje e pelos
 * validadores das demais entidades quando forem implementados (pet, produto...).
 */
final class BrazilianFormatParser
{
    /** `05/03/2026` → `2026-03-05` (5 de março, não 3 de maio). Formato ISO passa direto. */
    public function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $matches) === 1) {
            [, $day, $month, $year] = $matches;

            return "{$year}-{$month}-{$day}";
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /** `1.234,56` → `1234.56`. Ponto de milhar removido antes de trocar a vírgula decimal. */
    public function parseDecimal(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $withoutThousands = str_replace('.', '', $value);
        $normalized = str_replace(',', '.', $withoutThousands);

        return is_numeric($normalized) ? (float) $normalized : null;
    }
}
