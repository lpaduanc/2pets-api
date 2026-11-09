<?php

namespace App\DataTransferObjects;

/**
 * CNPJ: 14 dígitos, sem máscara. Ver `DocumentNumber` para a regra de projeto completa.
 */
final readonly class Cnpj extends DocumentNumber
{
    public const DIGIT_COUNT = 14;

    /** Raiz (CNPJ-base): os 8 primeiros dígitos, comuns à matriz e às filiais. */
    public const ROOT_LENGTH = 8;

    /** Pesos do 1º dígito verificador; o 2º usa o 6 na frente (algoritmo da Receita Federal). */
    private const CHECK_DIGIT_WEIGHTS = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    /**
     * Dígitos verificadores conferem. Sequência de um dígito só (00000000000000…) passa no
     * cálculo, mas nunca foi emitida — é o "CNPJ de teste" digitado para fugir da validação.
     */
    public function hasValidCheckDigits(): bool
    {
        if (preg_match('/^(\d)\1+$/', $this->digits) === 1) {
            return false;
        }

        $firstDigit = self::checkDigit(substr($this->digits, 0, 12), self::CHECK_DIGIT_WEIGHTS);
        $secondDigit = self::checkDigit(substr($this->digits, 0, 12).$firstDigit, [6, ...self::CHECK_DIGIT_WEIGHTS]);

        return substr($this->digits, 12) === $firstDigit.$secondDigit;
    }

    public function root(): string
    {
        return substr($this->digits, 0, self::ROOT_LENGTH);
    }

    /** @param  list<int>  $weights */
    private static function checkDigit(string $base, array $weights): string
    {
        $sum = array_sum(array_map(fn (string $digit, int $weight): int => (int) $digit * $weight, str_split($base), $weights));
        $remainder = $sum % 11;

        return (string) ($remainder < 2 ? 0 : 11 - $remainder);
    }
}
