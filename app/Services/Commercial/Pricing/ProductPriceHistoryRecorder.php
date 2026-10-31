<?php

namespace App\Services\Commercial\Pricing;

use App\Enums\PriceChangeSource;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;

/**
 * Grava `product_price_history` (docs/gap-petflow/PLANO.md, 1.4) depois que o produto foi salvo.
 * Chamado pelos três caminhos que mexem no preço — cadastro manual, recebimento de compra e
 * importação —, cada um dizendo a origem.
 *
 * Dois métodos, e não um `wasRecentlyCreated || wasChanged`: o produto criado na própria compra
 * continua "recém-criado" na mesma instância quando o recebimento grava o preço, e a regra única
 * duplicaria a linha do preço inicial.
 */
final class ProductPriceHistoryRecorder
{
    /** Só quando o último `save()` mudou o preço de fato; salvar sem mexer no preço não grava. */
    public function recordIfPriceChanged(Product $product, PriceChangeSource $source, ?User $user): void
    {
        if (! $product->wasChanged('price')) {
            return;
        }

        $this->record($product, $source, $user);
    }

    /**
     * Grava sem perguntar: preço inicial do produto recém-cadastrado, ou mudança que o chamador
     * já constatou antes de outro `save()` (a reavaliação do custo) apagar o `wasChanged`.
     */
    public function record(Product $product, PriceChangeSource $source, ?User $user): void
    {
        ProductPriceHistory::create([
            'organization_id' => $product->organization_id,
            'professional_id' => $product->professional_id,
            'product_id' => $product->id,
            'price' => (string) ($product->price ?? '0'),
            'cost' => $product->unitCost(),
            'markup_percent' => $product->markup_percent,
            'user_id' => $user?->id,
            'source' => $source,
        ]);
    }
}
