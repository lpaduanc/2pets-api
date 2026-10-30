<?php

namespace App\Rules;

use App\Models\Appointment;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * O agendamento informado no item da venda é da mesma clínica de quem vende
 * (`CommercialScopeResolver`, o par `organization_id`/`professional_id`). `exists:appointments,id`
 * puro deixaria vincular o atendimento de outra clínica — e desligar a baixa de insumo do item.
 */
final class ScopedAppointmentExists implements ValidationRule
{
    public function __construct(
        private readonly User $caller,
        private readonly CommercialScopeResolver $scope,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = $this->scope->scopeQuery(Appointment::query(), $this->caller)
            ->whereKey($value)
            ->exists();

        if (! $exists) {
            $fail('Agendamento não encontrado.');
        }
    }
}
