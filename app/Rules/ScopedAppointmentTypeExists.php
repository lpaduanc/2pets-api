<?php

namespace App\Rules;

use App\Models\AppointmentType;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `appointment_type_id` (item 14, achado do frontend) só pode apontar para um tipo de
 * atendimento que o profissional chamador enxerga — mesma regra de escopo de
 * `CommercialScopeResolver::scopeQuery()` usada por `products`/`financial-accounts`/etc.
 * Um `exists:appointment_types,id` puro vazaria (e aceitaria) o id do catálogo de OUTRA
 * organização.
 */
final class ScopedAppointmentTypeExists implements ValidationRule
{
    public function __construct(
        private readonly User $caller,
        private readonly CommercialScopeResolver $scope,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = $this->scope->scopeQuery(AppointmentType::query(), $this->caller)
            ->where('active', true)
            ->whereKey($value)
            ->exists();

        if (! $exists) {
            $fail('Tipo de atendimento não encontrado.');
        }
    }
}
