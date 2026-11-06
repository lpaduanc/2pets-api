<?php

namespace App\Rules;

use App\Models\CostCenter;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `cost_center_id` (PLANO 2.4) só aponta para um centro ATIVO que o chamador enxerga — mesmo
 * escopo de `CommercialScopeResolver::scopeQuery()`. Um `exists:cost_centers,id` puro aceitaria
 * o centro de outra organização.
 */
final class ScopedCostCenterExists implements ValidationRule
{
    public function __construct(private readonly ?User $caller, private readonly bool $onlyActive = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $exists = $this->caller !== null && app(CommercialScopeResolver::class)->scopeQuery(CostCenter::query(), $this->caller)
            ->when($this->onlyActive, fn ($q) => $q->where('active', true))
            ->whereKey($value)
            ->exists();

        if (! $exists) {
            $fail('Centro de custo não encontrado.');
        }
    }
}
