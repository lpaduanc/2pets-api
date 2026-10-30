<?php

namespace App\Http\Requests\Commercial;

use App\Models\User;
use App\Rules\ScopedProductExists;
use App\Services\Commercial\CommercialScopeResolver;

/**
 * Regras da ficha técnica de insumos (PLANO 1.3), as mesmas no salvar (`PUT services/{id}/
 * supplies`) e na prévia (`POST services/cost-preview`) — a prévia recusa o que o salvar
 * recusaria. Quantidade com até 3 casas (`service_supplies.quantity decimal(12,3)`); custo
 * direto em R$, 2 casas, recusado (não arredondado) quando vier com mais.
 */
final class ServiceSupplyRules
{
    /** @return array<string, mixed> */
    public static function for(User $user, CommercialScopeResolver $scope): array
    {
        return [
            'direct_cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'items' => ['present', 'array', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', new ScopedProductExists($user, $scope)],
            'items.*.quantity' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999999.999'],
        ];
    }
}
