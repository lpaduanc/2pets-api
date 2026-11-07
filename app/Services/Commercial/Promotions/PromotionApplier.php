<?php

namespace App\Services\Commercial\Promotions;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use App\Support\Money\Money;
use Illuminate\Support\Collection;

/**
 * Aplica as promoções vigentes da organização da venda às linhas (PLANO 3.2). Chamado
 * AUTOMATICAMENTE a cada mudança no carrinho (`SaleService::refreshTotals`, enquanto a venda é
 * editável) e por `POST pdv/cart/evaluate`. Recalcula do zero a cada vez (idempotente): grava
 * `promotion_id`/`promotion_discount` e o `total` de cada linha. Linha de brinde/cortesia e
 * pacote não entram. Venda paga não muda mais: o que ficou gravado é o que valeu.
 */
final class PromotionApplier
{
    public function __construct(private readonly PromotionEngine $engine) {}

    /** @return array<string, mixed> o resultado do motor, com as linhas por `sale_item_id` */
    public function apply(Sale $sale): array
    {
        $items = $sale->items()->with('sellable')->orderBy('id')->get();
        $eligible = $items->filter(fn (SaleItem $item): bool => ! $item->isCourtesy() && in_array($item->sellable_type, [Product::class, Service::class], true));
        $result = $this->engine->evaluate($eligible->map(fn (SaleItem $item): array => $this->line($item))->values()->all(), $this->promotionsFor($sale)->map->toEngine()->all());

        foreach ($items as $item) {
            $line = $result['lines'][$item->id] ?? null;
            $item->promotion_id = $line['promotion_id'] ?? null;
            $item->promotion_discount = $line === null ? '0.00' : $line['promotion_discount'];
            $item->total = $item->calculateTotal();

            if ($item->isDirty()) {
                $item->save();
            }
        }

        return $result;
    }

    /** @return Collection<int, Promotion> */
    public function promotionsFor(Sale $sale): Collection
    {
        return Promotion::query()->inEffect()
            ->when($sale->organization_id !== null,
                fn ($q) => $q->where('organization_id', $sale->organization_id),
                fn ($q) => $q->whereNull('organization_id')->where('professional_id', $sale->professional_id))
            ->orderBy('id')->get();
    }

    /** @return array<string, mixed> */
    private function line(SaleItem $item): array
    {
        $sellable = $item->sellable;

        return [
            'id' => $item->id,
            'sellable_type' => $item->sellable_type === Product::class ? 'product' : 'service',
            'sellable_id' => $item->sellable_id,
            'group_id' => $sellable?->product_group_id,
            'brand_id' => $sellable instanceof Product ? $sellable->brand_id : null,
            'unit_price' => (string) $item->unit_price,
            'quantity' => (string) $item->quantity,
        ];
    }

    /** Total de promoção já gravado nas linhas da venda. */
    public static function totalOf(Sale $sale): string
    {
        return Money::of($sale->items()->sum('promotion_discount'))->toString();
    }
}
