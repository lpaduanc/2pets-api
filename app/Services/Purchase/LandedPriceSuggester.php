<?php

namespace App\Services\Purchase;

use App\Models\Product;
use App\Models\PurchaseItem;
use App\Services\Commercial\PricingService;
use App\Support\Money\Decimal;
use App\Support\Money\Money;

/**
 * Markup e preço sugerido sobre o custo REAL por unidade de estoque (docs/gap-petflow/PLANO.md,
 * 1.2). Uma regra só para a gravação da compra, a prévia de custo e a conferência do XML — a
 * tela nunca sugere sobre o preço da caixa e grava sobre a unidade.
 *
 * Ordem: markup do item; senão o implícito no preço aplicado; senão o markup vigente do produto
 * (`PricingService::repriceForNewCost`); senão nenhum. `PricingService` ainda é float — borda
 * legada, só na saída.
 */
final class LandedPriceSuggester
{
    public function __construct(private readonly PricingService $pricing) {}

    /**
     * @param  array<string, mixed>  $item  markup_percent?, applied_sale_price?
     * @return array{0: float|null, 1: float|null} markup do item e preço sugerido
     */
    public function suggest(?Product $product, array $item, string $landedUnitCost): array
    {
        $cost = Decimal::toFloat($landedUnitCost, PurchaseItem::LANDED_UNIT_COST_SCALE);
        $markup = isset($item['markup_percent']) ? (float) $item['markup_percent'] : null;

        if ($markup === null && isset($item['applied_sale_price'])) {
            $markup = $this->pricing->markupFromCost($cost, Decimal::toFloat($item['applied_sale_price'], Money::SCALE));
        }

        if ($markup !== null) {
            return [$markup, $this->pricing->priceFromMarkup($cost, $markup)];
        }

        if ($product?->markup_percent === null) {
            return [null, null];
        }

        return [null, $this->pricing->repriceForNewCost($product, $cost)];
    }

    /**
     * Preço sugerido como string de 2 casas (resposta das prévias), ou `null`.
     *
     * @param  array<string, mixed>  $item
     */
    public function suggestedPrice(?Product $product, array $item, string $landedUnitCost): ?string
    {
        $suggested = $this->suggest($product, $item, $landedUnitCost)[1];

        return $suggested === null ? null : Money::of((string) $suggested)->toString();
    }
}
