<?php

namespace App\Rules;

use App\DataTransferObjects\Cnpj;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Formato do CNPJ já normalizado (`Cnpj::stripMask()` no `prepareForValidation()`): 14 caracteres,
 * os 12 primeiros letras maiúsculas ou dígitos e os 2 últimos dígitos (CNPJ alfanumérico, IN RFB
 * 2.229/2024). Substitui o antigo `digits:14`, que recusava o CNPJ com letra. Tamanho errado tem
 * mensagem própria, para o usuário ver "14 caracteres" e não "inválido" quando faltou um.
 * O dígito verificador é do `ValidCnpj`.
 */
final class CnpjFormat implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalized = (string) $value;

        if (strlen($normalized) !== Cnpj::DIGIT_COUNT) {
            $fail('O CNPJ deve ter 14 caracteres.');

            return;
        }

        if (Cnpj::tryParse($normalized)?->digits !== $normalized) {
            $fail('CNPJ inválido: as 12 primeiras posições aceitam letras e números; as 2 últimas, só números.');
        }
    }
}
