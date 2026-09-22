<?php

namespace App\Services\Import;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\PricingService;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\DB;

/**
 * Cria o produto importado (item 26 do backlog gap-simplesvet) — mesmo caminho de
 * `ProductController::store()`: `PricingService` resolve preço/markup, e saldo inicial nunca
 * grava direto na coluna, vira `StockMovementType::OPENING_BALANCE` pelo `StockService` (doc 07
 * — "usar o mecanismo de ajuste/movimentação", nunca um `UPDATE` silencioso no cadastro).
 */
final class ImportedProductProvisioner
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly StockService $stock,
        private readonly ProductSkuGenerator $skuGenerator,
        private readonly CommercialScopeResolver $scope,
    ) {}

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function create(array $normalized, User $actor): Product
    {
        return DB::transaction(function () use ($normalized, $actor): Product {
            $group = $this->findGroup($normalized['product_group_id'] ?? null);
            $data = $this->pricing->resolvePricing($this->attributesFrom($normalized, $actor), $group);

            $openingBalance = (int) ($data['stock_quantity'] ?? 0);
            $data['stock_quantity'] = 0;

            $product = Product::create($data + $this->scope->ownershipFor($actor));
            $this->recordOpeningBalance($product, $openingBalance, $actor);

            return $product;
        });
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array<string, mixed>
     */
    private function attributesFrom(array $normalized, User $actor): array
    {
        return [
            'name' => $normalized['name'],
            'sku' => $normalized['sku'] ?? $this->skuGenerator->generateFrom($normalized['name'], $actor),
            'code' => $normalized['code'],
            'gtin' => $normalized['gtin'],
            'ncm' => $normalized['ncm'],
            'unit_of_sale' => $normalized['unit_of_sale'],
            'brand_id' => $normalized['brand_id'],
            'product_group_id' => $normalized['product_group_id'],
            'price' => $normalized['price'],
            'average_cost' => $normalized['average_cost'],
            'markup_percent' => $normalized['markup_percent'],
            'stock_quantity' => $normalized['stock_quantity'],
            'controls_stock' => true,
            'is_active' => true,
        ];
    }

    private function findGroup(?int $groupId): ?ProductGroup
    {
        return $groupId === null ? null : ProductGroup::find($groupId);
    }

    private function recordOpeningBalance(Product $product, int $openingBalance, User $actor): void
    {
        if ($openingBalance <= 0) {
            return;
        }

        $this->stock->in($product, StockMovementType::OPENING_BALANCE, $openingBalance, [
            'user' => $actor,
            'notes' => 'Saldo informado na importação de produtos.',
        ]);
    }
}
