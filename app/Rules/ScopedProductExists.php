<?php

namespace App\Rules;

use App\Models\Product;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * O produto informado existe no catálogo comercial VISÍVEL para quem chama
 * (`CommercialScopeResolver`). `exists:products,id` puro aceitaria o insumo de outra clínica na
 * ficha técnica — e a baixa do atendimento mexeria no estoque dela.
 */
final class ScopedProductExists implements ValidationRule
{
    public function __construct(
        private readonly User $caller,
        private readonly CommercialScopeResolver $scope,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = $this->scope->scopeQuery(Product::query(), $this->caller)
            ->whereKey($value)
            ->exists();

        if (! $exists) {
            $fail('Produto não encontrado.');
        }
    }
}
