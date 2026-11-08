<?php

namespace App\Http\Resources\Stock;

use App\Http\Resources\Fiscal\FiscalDocumentResource;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Devolução de compra (spec fase4-fiscal §3.6). Dinheiro como string de 2 casas.
 *
 * @mixin PurchaseReturn
 */
class PurchaseReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_id' => $this->purchase_id,
            'purchase' => $this->whenLoaded('purchase', fn (): array => [
                'id' => $this->purchase->id,
                'code' => $this->purchase->code,
                'invoice_number' => $this->purchase->invoice_number,
                'invoice_key' => $this->purchase->invoice_key,
                'supplier' => $this->purchase->relationLoaded('supplier') && $this->purchase->supplier !== null
                    ? ['id' => $this->purchase->supplier->id, 'name' => $this->purchase->supplier->trade_name ?: $this->purchase->supplier->legal_name]
                    : null,
            ]),
            'reason' => $this->reason,
            'total' => (string) $this->total,
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : ['id' => $this->user->id, 'name' => $this->user->name]),
            'items' => $this->whenLoaded('items', fn (): array => $this->items->map(fn (PurchaseReturnItem $item): array => [
                'id' => $item->id,
                'purchase_item_id' => $item->purchase_item_id,
                'product_id' => $item->purchaseItem?->product_id,
                'description' => $item->purchaseItem?->product?->name ?? $item->purchaseItem?->description_on_invoice,
                'quantity' => (string) $item->quantity,
                'unit_cost' => (string) $item->unit_cost,
                'products_total' => (string) $item->products_total,
                'discount_share' => (string) $item->discount_share,
                'ipi_share' => (string) $item->ipi_share,
                'icms_st_share' => (string) $item->icms_st_share,
                'total' => (string) $item->total,
            ])->values()->all()),
            'fiscal_documents' => FiscalDocumentResource::collection($this->whenLoaded('fiscalDocuments')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
