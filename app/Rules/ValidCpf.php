<?php

namespace App\Rules;

use App\Services\CpfValidationService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida o dígito verificador do CPF.
 *
 * Espera o valor já normalizado (só dígitos) — o Form Request que usa esta regra precisa
 * rodar `Cpf::stripMask()` em `prepareForValidation()` antes da validação, senão o CPF
 * mascarado nunca bate com o cálculo do dígito verificador.
 */
final class ValidCpf implements ValidationRule
{
    public function __construct(private readonly CpfValidationService $cpfValidationService) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->cpfValidationService->validate((string) $value)) {
            $fail('CPF inválido');
        }
    }
}
