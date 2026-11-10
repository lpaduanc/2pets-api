<?php

namespace App\DataTransferObjects;

/**
 * CNPJ normalizado (sem máscara). Ver `DocumentNumber` para a regra de projeto completa.
 *
 * CNPJ alfanumérico (IN RFB 2.229/2024, em produção desde julho de 2026): 12 posições com letras
 * MAIÚSCULAS ou dígitos (raiz = 8 primeiras, ordem do estabelecimento = 4 seguintes) + 2 dígitos
 * verificadores numéricos. O DV continua módulo 11, mas cada caractere vale o código ASCII − 48
 * ('0'..'9' → 0..9, 'A' → 17 … 'Z' → 42): para CNPJ só numérico o cálculo é o de sempre, então os
 * CNPJs existentes continuam válidos sem mudança. Fontes: Receita Federal, "Perguntas e respostas
 * — CNPJ alfanumérico" (perguntas 2 e 14) e NT Conjunta DFe 2025.001 v1.00, item 2.
 */
final readonly class Cnpj extends DocumentNumber
{
    /** Tamanho do CNPJ normalizado (14 caracteres; no alfanumérico nem todos são dígitos). */
    public const DIGIT_COUNT = 14;

    /** Raiz (CNPJ-base): os 8 primeiros caracteres, comuns à matriz e às filiais. */
    public const ROOT_LENGTH = 8;

    /** Formato do valor normalizado: 12 letras maiúsculas/dígitos + 2 dígitos verificadores. */
    public const PATTERN = '/^[0-9A-Z]{12}[0-9]{2}$/';

    /** Pesos do 1º dígito verificador; o 2º usa o 6 na frente (algoritmo da Receita Federal). */
    private const CHECK_DIGIT_WEIGHTS = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    /** Valor de cada caractere no módulo 11: código ASCII menos o de '0'. */
    private const ASCII_OFFSET = 48;

    /**
     * Tira a máscara (`.`, `/`, `-`, espaço e qualquer outro símbolo) e passa as letras para
     * maiúscula: `12.abc.345/01de-35` → `12ABC34501DE35`. Letras são parte do número agora — não
     * podem mais ser descartadas como no CPF.
     */
    public static function stripMask(?string $raw): string
    {
        return (string) preg_replace('/[^0-9A-Z]/', '', strtoupper((string) $raw));
    }

    /**
     * Dígitos verificadores conferem. Sequência de um dígito só (00000000000000…) passa no
     * cálculo, mas nunca foi emitida — é o "CNPJ de teste" digitado para fugir da validação.
     */
    public function hasValidCheckDigits(): bool
    {
        if (preg_match('/^(\d)\1+$/', $this->digits) === 1) {
            return false;
        }

        $base = substr($this->digits, 0, 12);
        $firstDigit = self::checkDigit($base, self::CHECK_DIGIT_WEIGHTS);
        $secondDigit = self::checkDigit($base.$firstDigit, [6, ...self::CHECK_DIGIT_WEIGHTS]);

        return substr($this->digits, 12) === $firstDigit.$secondDigit;
    }

    /** Tem letra (CNPJ alfanumérico) — o leiaute fiscal antigo (só dígitos) não o aceita. */
    public function isAlphanumeric(): bool
    {
        return ! ctype_digit($this->digits);
    }

    public function root(): string
    {
        return substr($this->digits, 0, self::ROOT_LENGTH);
    }

    protected static function isWellFormed(string $normalized): bool
    {
        return preg_match(self::PATTERN, $normalized) === 1;
    }

    /** @param  list<int>  $weights */
    private static function checkDigit(string $base, array $weights): string
    {
        $sum = array_sum(array_map(fn (string $character, int $weight): int => (ord($character) - self::ASCII_OFFSET) * $weight, str_split($base), $weights));
        $remainder = $sum % 11;

        return (string) ($remainder < 2 ? 0 : 11 - $remainder);
    }
}
