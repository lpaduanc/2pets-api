<?php

namespace App\Http\Resources\Stock;

use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockCount;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Linha do kardex. `reference` traduz o morph para um rótulo que o app mostra sem conhecer
 * namespace PHP.
 *
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    public const RESOURCE_RELATIONS = ['product:id,name,unit_of_sale', 'batch:id,batch_code,expires_at', 'reason:id,name', 'user:id,name'];

    private const REFERENCE_LABELS = [
        Sale::class => ['sale', 'Venda'],
        Purchase::class => ['purchase', 'Compra'],
        StockCount::class => ['stock_count', 'Inventário'],
        SaleReturn::class => ['sale_return', 'Devolução'],
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product' => $this->whenLoaded('product', fn () => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'unit_of_sale' => $this->product->unit_of_sale,
            ] : null),
            'product_id' => $this->product_id,
            'batch' => $this->whenLoaded('batch', fn () => $this->batch ? [
                'id' => $this->batch->id,
                'batch_code' => $this->batch->batch_code,
                'expires_at' => $this->batch->expires_at?->toDateString(),
            ] : null),
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'direction' => $this->direction->value,
            'quantity' => $this->quantity,
            'signed_quantity' => $this->signedQuantity(),
            'unit_cost' => (float) $this->unit_cost,
            'total_cost' => (float) $this->total_cost,
            'balance_after' => $this->balance_after,
            'reason' => $this->whenLoaded('reason', fn () => $this->reason ? ['id' => $this->reason->id, 'name' => $this->reason->name] : null),
            'reference' => $this->referencePayload(),
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'notes' => $this->notes,
        ];
    }

    /**
     * @return array{type: string, id: int, label: string}|null
     */
    private function referencePayload(): ?array
    {
        if ($this->reference_type === null) {
            return $this->type->isManual() ? ['type' => 'manual', 'id' => $this->id, 'label' => 'Lançamento manual'] : null;
        }

        [$type, $label] = self::REFERENCE_LABELS[$this->reference_type] ?? ['other', 'Documento'];

        return ['type' => $type, 'id' => (int) $this->reference_id, 'label' => $label.' #'.$this->reference_id];
    }
}
