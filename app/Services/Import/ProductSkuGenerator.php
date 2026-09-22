<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Support\Str;

/**
 * `products.sku` é obrigatório no cadastro (`StoreProductRequest`), mas planilha legada raramente
 * traz um — item 26 do backlog gap-simplesvet gera um a partir do nome/código em vez de
 * bloquear a linha por um campo que o sistema de origem nunca teve.
 */
final class ProductSkuGenerator
{
    private const MAX_BASE_LENGTH = 40;

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function generateFrom(string $name, User $actor): string
    {
        $base = Str::of($name)->slug()->upper()->limit(self::MAX_BASE_LENGTH, '')->toString();
        $base = $base === '' ? 'PRODUTO' : $base;

        return $this->firstAvailable($base, $actor);
    }

    private function firstAvailable(string $base, User $actor): string
    {
        $candidate = $base;
        $suffix = 1;

        while ($this->skuTaken($candidate, $actor)) {
            $suffix++;
            $candidate = "{$base}-{$suffix}";
        }

        return $candidate;
    }

    private function skuTaken(string $candidate, User $actor): bool
    {
        return $this->scope->scopeQuery(Product::withTrashed(), $actor)
            ->whereRaw('lower(sku) = ?', [mb_strtolower($candidate)])
            ->exists();
    }
}
