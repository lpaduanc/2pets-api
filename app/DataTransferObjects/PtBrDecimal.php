<?php

namespace App\DataTransferObjects;

/**
 * Interpreta um número escrito no padrão brasileiro (vírgula decimal, ponto de milhar) —
 * usado por `ExamResultValue`/`ExamReferenceRange`, que chegam como texto livre em pt-BR
 * (ex.: resultado de exame, faixa de referência do laudo).
 *
 * Heurística — documentada de propósito, não é livre de ambiguidade:
 *   - Com vírgula: a vírgula é o separador decimal; qualquer ponto presente é separador de
 *     milhar e é descartado. "1.850,5" → 1850.5 / "6,8" → 6.8
 *   - Sem vírgula, com um único ponto seguido de exatamente 3 dígitos e parte inteira que
 *     não começa em zero: o ponto é separador de milhar. "1.250" → 1250 / "100.000" → 100000
 *   - Qualquer outro uso de ponto é decimal: "14.2" → 14.2 / "0.15" → 0.15
 *   - Texto com qualquer caractere fora de dígito/vírgula/ponto/sinal ("Negativo", "<0,1",
 *     "Reagente", "Ausente") não é numérico — devolve null. O chamador preserva o texto
 *     original à parte; não existe "meio parseado".
 */
final class PtBrDecimal
{
    private const THOUSANDS_PATTERN = '/^-?[1-9]\d*\.\d{3}$/';

    public static function parse(?string $raw): ?float
    {
        $trimmed = trim((string) $raw);

        if ($trimmed === '' || ! preg_match('/^-?[\d.,]+$/', $trimmed)) {
            return null;
        }

        return self::toFloat($trimmed);
    }

    private static function toFloat(string $value): float
    {
        if (str_contains($value, ',')) {
            return (float) str_replace(',', '.', str_replace('.', '', $value));
        }

        if (preg_match(self::THOUSANDS_PATTERN, $value)) {
            return (float) str_replace('.', '', $value);
        }

        return (float) $value;
    }
}
