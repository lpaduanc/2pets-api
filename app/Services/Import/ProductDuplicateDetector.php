<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Produto por GTIN/código, escopado ao dono (item 26 do backlog gap-simplesvet, regra 5 —
 * "produto por GTIN/código... mesmo padrão de resolução de dono" de `Brand`/`ProductGroup`).
 * GTIN tem prioridade — é o identificador universal; código é o identificador interno do
 * sistema de origem, fallback para planilha sem código de barras.
 */
final class ProductDuplicateDetector
{
    public function __construct(private readonly CommercialScopeResolver $scope) {}

    /**
     * @param  array{gtin: ?string, code: ?string}  $normalized
     */
    public function findExisting(array $normalized, User $actor): ?Product
    {
        if (! empty($normalized['gtin'])) {
            $byGtin = $this->scopedQuery($actor)->where('gtin', $normalized['gtin'])->first();
            if ($byGtin !== null) {
                return $byGtin;
            }
        }

        if (! empty($normalized['code'])) {
            return $this->scopedQuery($actor)->where('code', $normalized['code'])->first();
        }

        return null;
    }

    /**
     * @return Builder<Product>
     */
    private function scopedQuery(User $actor): Builder
    {
        return $this->scope->scopeQuery(Product::query(), $actor);
    }
}
