<?php

namespace App\Http\Resources\Commercial;

use App\Models\Sale;
use App\Services\Commercial\QuoteIssuer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Orçamento na visão do TUTOR (app) — docs/gap-simplesvet/24-orcamentos.md.
 *
 * Não estende `SaleResource` de propósito: aquele expõe `notes` (observação interna da
 * clínica), custo congelado e o funcionário responsável por item — nada disso é do tutor.
 * Lista branca, como a página pública.
 *
 * @mixin Sale
 */
class TutorQuoteResource extends JsonResource
{
    public const RELATIONS = ['items', 'pet', 'organization', 'professional.professional'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $issuer = QuoteIssuer::for($this->resource);

        return [
            'id' => $this->id,
            'number' => $this->number,
            ...QuoteResource::quoteFields($this->resource),
            'can_decide' => $this->effectiveQuoteStatus()?->isAwaitingDecision() === true,
            'valid_until' => $this->valid_until?->toDateString(),
            'clinic' => ['name' => $issuer['name'], 'city' => $issuer['city'], 'state' => $issuer['state']],
            'pet' => $this->whenLoaded('pet', fn () => $this->pet ? [
                'id' => $this->pet->id,
                'name' => $this->pet->name,
                'species' => $this->pet->species,
            ] : null),
            'pet_id' => $this->pet_id,
            ...self::amounts($this->resource),
            'printed_notes' => $this->printed_notes,
            'medical_record_id' => $this->medical_record_id,
            'converted_to_sale_id' => $this->converted_to_sale_id,
            'versions' => $this->when(
                $this->resource->relationLoaded('familyVersions'),
                fn () => $this->resource->getRelation('familyVersions')->map(fn (Sale $version) => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'quote_status' => $version->effectiveQuoteStatus()?->value,
                    'quote_status_label' => $version->effectiveQuoteStatus()?->label(),
                    ...self::amounts($version),
                ])->values()
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Itens e totais em lista branca — reaproveitado pela página pública.
     *
     * @return array<string, mixed>
     */
    public static function amounts(Sale $quote): array
    {
        return [
            'items' => $quote->items->map(fn ($item) => [
                'description' => $item->description,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'discount' => (float) $item->discount,
                'total' => (float) $item->total,
            ])->values(),
            'subtotal' => (float) $quote->subtotal,
            'discount_amount' => (float) $quote->discount_amount,
            'total' => (float) $quote->total,
        ];
    }
}
