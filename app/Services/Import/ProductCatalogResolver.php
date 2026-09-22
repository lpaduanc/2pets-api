<?php

namespace App\Services\Import;

use App\Models\Brand;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;

/**
 * Resolve marca/grupo de um produto importado pelo nome, criando o cadastro quando não existe
 * (item 26 do backlog gap-simplesvet — "reaproveitar grupos/marcas do item 08"). Ao contrário
 * do catálogo de pelagem (item 23), `brands`/`product-groups` não são geridos pelo
 * `CatalogController` nem gated por `catalog.manage` — são CRUD comercial comum
 * (`routes/api.php`, `brands`/`product-groups`), então quem já tem `data.import` (owner ativo)
 * já tem autoridade plena sobre o próprio catálogo comercial; criar aqui não abre uma porta
 * nova de permissão.
 */
final class ProductCatalogResolver
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function resolveBrand(?string $name, User $actor): ?int
    {
        return $this->resolveOrCreate(Brand::class, $name, $actor);
    }

    public function resolveGroup(?string $name, User $actor): ?int
    {
        return $this->resolveOrCreate(ProductGroup::class, $name, $actor);
    }

    /**
     * @param  class-string<Brand|ProductGroup>  $modelClass
     */
    private function resolveOrCreate(string $modelClass, ?string $name, User $actor): ?int
    {
        $trimmed = trim((string) $name);
        if ($trimmed === '') {
            return null;
        }

        $existing = $this->scope->scopeQuery($modelClass::query(), $actor)
            ->whereRaw('lower(name) = ?', [mb_strtolower($trimmed)])
            ->first();

        if ($existing !== null) {
            return $existing->id;
        }

        $created = $modelClass::create([...$this->scope->ownershipFor($actor), 'name' => $trimmed, 'active' => true]);

        return $created->id;
    }
}
