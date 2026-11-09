<?php

namespace App\Rules;

use App\DataTransferObjects\Cnpj;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida o dígito verificador do CNPJ.
 *
 * Espera o valor já normalizado (só dígitos) — mesmo contrato do `ValidCpf`: o Form Request roda
 * `Cnpj::stripMask()` em `prepareForValidation()`. O tamanho é checado antes por `digits:14`
 * (com `bail`), para o usuário ver "14 dígitos" e não "inválido" quando faltou um número.
 */
final class ValidCnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (Cnpj::tryParse((string) $value)?->hasValidCheckDigits() !== true) {
            $fail('CNPJ inválido: confira os números digitados.');
        }
    }
}
