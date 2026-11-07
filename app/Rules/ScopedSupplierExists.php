<?php

namespace App\Rules;

use App\Models\Supplier;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Fornecedor padrão do produto (PLANO 3.4) só aponta para um fornecedor que o chamador
 * enxerga (mesmo escopo de `CommercialScopeResolver::scopeQuery()`); `exists:suppliers,id`
 * puro aceitaria o de outra organização.
 */
final class ScopedSupplierExists implements ValidationRule
{
    public function __construct(private readonly ?User $caller) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = $this->caller !== null && app(CommercialScopeResolver::class)->scopeQuery(Supplier::query(), $this->caller)->whereKey($value)->exists();

        if (! $exists) {
            $fail('Fornecedor não encontrado.');
        }
    }
}
