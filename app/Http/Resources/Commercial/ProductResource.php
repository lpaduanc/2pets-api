<?php

namespace App\Http\Resources\Commercial;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** Relações que a listagem do doc 08 precisa para não cair em N+1. */
    public const RESOURCE_RELATIONS = ['group:id,name', 'brand:id,name', 'category:id,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'sku' => $this->sku,
            'code' => $this->code,
            'gtin' => $this->gtin,
            'ncm' => $this->ncm,
            'cest' => $this->cest,
            'unit_of_sale' => $this->unit_of_sale,
            'purpose' => $this->purpose?->value,
            'purpose_label' => $this->purpose?->label(),

            'group' => $this->whenLoaded('group', fn () => [
                'id' => $this->group->id,
                'name' => $this->group->name,
            ]),
            'brand' => $this->whenLoaded('brand', fn () => [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
            ]),
            'product_group_id' => $this->product_group_id,
            'brand_id' => $this->brand_id,
            'immunization_product_id' => $this->immunization_product_id,

            'price' => (float) $this->price,
            // Custo/margem só para quem gerencia o catálogo (`products.update`) — achado do
            // frontend-specialist: `clinic_vet` e recepção têm `products.view` só para o
            // seletor clínico/consulta de preço e não podem ver quanto a clínica paga nem a
            // margem. Esconder aqui, não no front (o campo nem sai no payload).
            'average_cost' => $this->when($this->canSeeCost($request), fn () => (float) $this->average_cost),
            'last_cost' => $this->when($this->canSeeCost($request), fn () => (float) $this->last_cost),
            'markup_percent' => $this->when(
                $this->canSeeCost($request),
                fn () => $this->markup_percent === null ? null : (float) $this->markup_percent,
            ),
            'commission_percent' => $this->when(
                $this->canSeeCost($request),
                fn () => $this->commission_percent === null ? null : (float) $this->commission_percent,
            ),

            'stock_quantity' => $this->stock_quantity,
            'min_stock' => $this->min_stock,
            'max_stock' => $this->max_stock,
            'stock_situation' => $this->stockSituation(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'is_expired' => $this->isExpired(),

            'show_in_price_list' => $this->show_in_price_list,
            'allow_price_override' => $this->allow_price_override,
            'controls_stock' => $this->controls_stock,
            'track_batches' => $this->track_batches,
            'is_active' => $this->is_active,

            'images' => $this->images,
            // Seletor clínico (spec produtos-estoque-consolidado item 4): lotes com saldo,
            // ordenados FEFO, para o vet escolher qual foi aplicado. Só presente quando o
            // controller pediu `with_batches=1` — sem isto vira N+1 em toda listagem.
            'batches' => $this->whenLoaded('batches', fn () => $this->batches
                ->sortBy([['expires_at', 'asc'], ['id', 'asc']])
                ->values()
                ->map(fn ($batch) => [
                    'id' => $batch->id,
                    'batch_code' => $batch->batch_code,
                    'expires_at' => $batch->expires_at?->toDateString(),
                    'quantity' => $batch->quantity,
                ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function canSeeCost(Request $request): bool
    {
        return (bool) $request->user()?->can('products.update');
    }
}
