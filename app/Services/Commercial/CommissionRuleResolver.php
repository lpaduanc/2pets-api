<?php

namespace App\Services\Commercial;

use App\Enums\CommissionScope;
use App\Models\CommissionRule;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Service;
use Illuminate\Support\Collection;

/**
 * "Qual regra vale para este item de venda" — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md, regra de negócio 2.
 *
 * Hierarquia (mais específico vence): item (`product`/`service`) > grupo
 * (`product_group`) > geral (`all`). Dentro de cada nível, uma regra do PRÓPRIO `staff_id`
 * vence a regra geral daquele nível ("todo mundo ganha 5%, exceto a Maria que ganha 8%,
 * exceto quando ela vende Ração Premium que é 3%").
 *
 * `scope = specialty` não é resolvido nesta rodada: a plataforma ainda não amarra
 * especialidade a `organization_members` de um jeito não ambíguo (ver a migration). Uma regra
 * cadastrada com esse escopo fica sem efeito até o dia em que essa ligação existir — registrado
 * como lacuna conhecida, não como bug.
 */
final class CommissionRuleResolver
{
    /**
     * `null` quando nenhuma regra casa — quem chama cai no fallback do `commission_percent`
     * congelado no próprio `sale_items` (doc 08).
     */
    public function resolve(SaleItem $item): ?CommissionRule
    {
        $sale = $item->sale;
        $rules = $this->candidateRules($sale->organization_id, $sale->professional_id, $sale->sold_at ?? now());

        foreach ($this->tiersFor($item) as [$scope, $scopeId]) {
            $rule = $this->pickForTier($rules, $scope, $scopeId, $item->staff_id);

            if ($rule !== null) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @return list<array{0: CommissionScope, 1: int|null}>
     */
    private function tiersFor(SaleItem $item): array
    {
        $tiers = [];

        if ($item->sellable_type === Product::class) {
            $tiers[] = [CommissionScope::PRODUCT, $item->sellable_id];
        } elseif ($item->sellable_type === Service::class) {
            $tiers[] = [CommissionScope::SERVICE, $item->sellable_id];
        }

        $groupId = $item->sellable?->product_group_id;

        if ($groupId !== null) {
            $tiers[] = [CommissionScope::PRODUCT_GROUP, $groupId];
        }

        $tiers[] = [CommissionScope::ALL, null];

        return $tiers;
    }

    /**
     * @return Collection<int, CommissionRule>
     */
    private function candidateRules(?int $organizationId, ?int $professionalId, \DateTimeInterface $asOf): Collection
    {
        return CommissionRule::query()
            ->active()
            ->when(
                $organizationId !== null,
                fn ($q) => $q->where('organization_id', $organizationId),
                fn ($q) => $q->where('professional_id', $professionalId)->whereNull('organization_id'),
            )
            ->get()
            ->filter(fn (CommissionRule $rule): bool => $rule->isValidOn($asOf));
    }

    /**
     * @param  Collection<int, CommissionRule>  $rules
     */
    private function pickForTier(Collection $rules, CommissionScope $scope, ?int $scopeId, ?int $staffId): ?CommissionRule
    {
        $matching = $rules->filter(fn (CommissionRule $rule): bool => $rule->scope === $scope && $rule->scope_id === $scopeId);

        return $matching->first(fn (CommissionRule $rule): bool => $rule->staff_id === $staffId)
            ?? $matching->first(fn (CommissionRule $rule): bool => $rule->staff_id === null);
    }
}
