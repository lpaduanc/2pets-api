<?php

namespace App\Http\Resources\Stock;

use App\Models\PurchaseItem;
use App\Support\Money\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Linha da prévia de devolução de compra (`POST purchases/{id}/returns/preview`): os mesmos
 * valores que o salvar congela em `purchase_return_items`.
 *
 * @property array{item: PurchaseItem, quantity: Quantity, amounts: array<string, string>} $resource
 */
class PurchaseReturnPreviewLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource['item'];

        return [
            'purchase_item_id' => $item->id,
            'product_id' => $item->product_id,
            'description' => $item->product?->name ?? $item->description_on_invoice,
            'quantity' => $this->resource['quantity']->toString(),
        ] + $this->resource['amounts'];
    }
}
