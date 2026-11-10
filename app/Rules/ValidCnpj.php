<?php

namespace App\Rules;

use App\DataTransferObjects\Cnpj;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida o dígito verificador do CNPJ, numérico ou alfanumérico (módulo 11 com ASCII − 48, ver
 * `Cnpj`).
 *
 * Espera o valor já normalizado (sem máscara, maiúsculo) — mesmo contrato do `ValidCpf`: o Form
 * Request roda `Cnpj::stripMask()` em `prepareForValidation()`. O formato é checado antes por
 * `CnpjFormat` (com `bail`), para o usuário ver "14 caracteres" e não "inválido" quando faltou um.
 */
final class ValidCnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (Cnpj::tryParse((string) $value)?->hasValidCheckDigits() !== true) {
            $fail('CNPJ inválido: confira os caracteres digitados.');
        }
    }
}
