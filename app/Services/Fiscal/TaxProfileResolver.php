<?php

namespace App\Services\Fiscal;

use App\DataTransferObjects\Fiscal\FiscalOwner;
use App\DataTransferObjects\Fiscal\ResolvedTaxProfile;
use App\Enums\TaxProfileSource;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\TaxProfile;
use App\Services\Fiscal\Calculation\TaxProfileChain;
use Illuminate\Support\Collection;

/**
 * Perfil tributário EFETIVO em lote — spec docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §3.3
 * (mesmo papel do `CostCenterResolver` do PLANO 2.4, mas a herança aqui sobe todos os pais).
 * Carrega produtos, a árvore de grupos (por nível, poucas queries numa árvore rasa), os perfis
 * ativos e o padrão do dono; a regra em si é a `TaxProfileChain` (pura).
 */
final class TaxProfileResolver
{
    private const NONE = ['id' => null, 'name' => null, 'source' => null];

    /**
     * @param  list<int>  $productIds
     * @return array<int, ResolvedTaxProfile|null> por `products.id`
     */
    public function forProducts(FiscalOwner $owner, array $productIds): array
    {
        $products = Product::withTrashed()->whereIn('id', array_values(array_unique($productIds)))->get(['id', 'tax_profile_id', 'product_group_id']);
        $resolved = $this->resolveProducts($owner, $products);
        $profiles = TaxProfile::withTrashed()->with('cfops')->whereIn('id', array_filter(array_map(fn (?array $pair): ?int => $pair[0] ?? null, $resolved)))->get()->keyBy('id');

        return array_map(
            fn (?array $pair): ?ResolvedTaxProfile => $pair === null ? null : new ResolvedTaxProfile($profiles[$pair[0]], $pair[1]),
            $resolved,
        );
    }

    /**
     * Preenche `Product::$effectiveTaxProfile` para o resource (sem N+1).
     *
     * @param  iterable<Product>  $products
     */
    public function annotateProducts(iterable $products): void
    {
        foreach (collect($products)->groupBy(fn (Product $product): string => $this->ownerKey(FiscalOwner::of($product))) as $group) {
            $resolved = $this->resolveProducts(FiscalOwner::of($group->first()), $group);
            $names = $this->names($resolved);
            $group->each(fn (Product $product) => $product->effectiveTaxProfile = $this->summary($resolved[$product->id] ?? null, $names));
        }
    }

    /**
     * Preenche `ProductGroup::$effectiveTaxProfile` dos grupos e dos filhos carregados.
     *
     * @param  iterable<ProductGroup>  $groups
     */
    public function annotateGroups(iterable $groups): void
    {
        $all = collect($groups)->flatMap(fn (ProductGroup $group): array => [$group, ...($group->relationLoaded('children') ? $group->children->all() : [])]);

        foreach ($all->groupBy(fn (ProductGroup $group): string => $this->ownerKey(FiscalOwner::of($group))) as $ownerGroups) {
            $chain = $this->chain(FiscalOwner::of($ownerGroups->first()), $ownerGroups->pluck('id')->all(), []);
            $resolved = $ownerGroups->mapWithKeys(fn (ProductGroup $group): array => [$group->id => $chain->resolveGroup($group->id)])->all();
            $names = $this->names($resolved);
            $ownerGroups->each(fn (ProductGroup $group) => $group->effectiveTaxProfile = $this->summary($resolved[$group->id], $names));
        }
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, array{0: int, 1: TaxProfileSource}|null>
     */
    private function resolveProducts(FiscalOwner $owner, Collection $products): array
    {
        $chain = $this->chain($owner, $products->pluck('product_group_id')->filter()->all(), $products->pluck('tax_profile_id')->filter()->all());

        return $products->mapWithKeys(fn (Product $product): array => [$product->id => $chain->resolve($product->tax_profile_id, $product->product_group_id)])->all();
    }

    /**
     * @param  list<int>  $groupIds
     * @param  list<int>  $ownProfileIds
     */
    private function chain(FiscalOwner $owner, array $groupIds, array $ownProfileIds): TaxProfileChain
    {
        $groups = $this->groupTree($groupIds);
        $defaultId = $owner->scope(TaxProfile::query())->active()->where('is_default', true)->value('id');
        $candidates = array_filter([...$ownProfileIds, ...array_column($groups, 'tax_profile_id'), $defaultId]);
        $active = TaxProfile::query()->active()->whereIn('id', array_values(array_unique($candidates)))->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return new TaxProfileChain($groups, $active, $defaultId === null ? null : (int) $defaultId);
    }

    /**
     * Os grupos pedidos e todos os pais, um nível por query (árvore rasa ⇒ 1–3 queries).
     *
     * @param  list<int>  $groupIds
     * @return array<int, array{parent_id: int|null, tax_profile_id: int|null}>
     */
    private function groupTree(array $groupIds): array
    {
        $groups = [];
        $requested = [];
        $pending = array_values(array_unique($groupIds));

        // `$requested` evita laço infinito quando o pai foi excluído (nunca volta na query).
        while ($pending !== []) {
            $requested = [...$requested, ...$pending];

            foreach (ProductGroup::query()->whereIn('id', $pending)->get(['id', 'parent_id', 'tax_profile_id']) as $group) {
                $groups[$group->id] = ['parent_id' => $group->parent_id, 'tax_profile_id' => $group->tax_profile_id];
            }

            $pending = array_values(array_diff(array_filter(array_unique(array_column($groups, 'parent_id'))), $requested));
        }

        return $groups;
    }

    /**
     * @param  array<int, array{0: int, 1: TaxProfileSource}|null>  $resolved
     * @return array<int, string>
     */
    private function names(array $resolved): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn (?array $pair): ?int => $pair[0] ?? null, $resolved))));

        return $ids === [] ? [] : TaxProfile::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @param  array{0: int, 1: TaxProfileSource}|null  $pair
     * @param  array<int, string>  $names
     * @return array{id: int|null, name: string|null, source: string|null}
     */
    private function summary(?array $pair, array $names): array
    {
        return $pair === null ? self::NONE : ['id' => $pair[0], 'name' => $names[$pair[0]] ?? null, 'source' => $pair[1]->value];
    }

    private function ownerKey(FiscalOwner $owner): string
    {
        return $owner->organizationId === null ? 'p'.$owner->professionalId : 'o'.$owner->organizationId;
    }
}
