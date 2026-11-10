<?php

namespace App\Support;

use App\DataTransferObjects\Cnpj;
use App\DataTransferObjects\Cpf;

/**
 * Campo que guarda CPF OU CNPJ (ex.: `suppliers.document`, documento do emitente/destinatário
 * fiscal). Desde o CNPJ alfanumérico (IN RFB 2.229/2024) não dá mais para limpar com "só dígitos":
 * a letra do CNPJ seria apagada. Regra: 11 dígitos = CPF (continua só numérico); 14 caracteres no
 * formato do CNPJ (12 letras/dígitos + 2 dígitos) = CNPJ.
 */
final class TaxpayerDocument
{
    public const CPF = 'cpf';

    public const CNPJ = 'cnpj';

    /** Validação de Form Request para o valor já normalizado (CPF de 11 dígitos ou CNPJ). */
    public const PATTERN = '/^(\d{11}|[0-9A-Z]{12}\d{2})$/';

    /** Sem máscara e em maiúsculas (CPF fica só com dígitos); vazio vira null. */
    public static function normalize(?string $raw): ?string
    {
        $normalized = Cnpj::stripMask($raw);

        return $normalized === '' ? null : $normalized;
    }

    /** `cpf`, `cnpj` ou null quando o valor (já normalizado) não tem o formato de nenhum dos dois. */
    public static function type(?string $normalized): ?string
    {
        $value = (string) $normalized;

        return match (true) {
            strlen($value) === Cpf::DIGIT_COUNT && ctype_digit($value) => self::CPF,
            preg_match(Cnpj::PATTERN, $value) === 1 => self::CNPJ,
            default => null,
        };
    }
}
