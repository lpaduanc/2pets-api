<?php

namespace App\Rules;

use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `tax_profile_id` (spec fase4-fiscal §6) só aponta para um perfil ATIVO que o chamador enxerga —
 * mesmo escopo de `CommercialScopeResolver::scopeQuery()`. Um `exists:tax_profiles,id` puro
 * aceitaria o perfil de outra clínica.
 */
final class ScopedTaxProfileExists implements ValidationRule
{
    public function __construct(private readonly ?User $caller) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = $this->caller !== null && app(CommercialScopeResolver::class)->scopeQuery(TaxProfile::query(), $this->caller)
            ->where('active', true)
            ->whereKey($value)
            ->exists();

        if (! $exists) {
            $fail('Perfil tributário não encontrado.');
        }
    }
}
