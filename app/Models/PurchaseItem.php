<?php

namespace App\Models;

use App\Enums\ProductPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id',
        'product_id',
        'purchase_order_item_id',
        'supplier_product_code',
        'description_on_invoice',
        'quantity',
        'unit',
        'unit_cost',
        'discount',
        'total_cost',
        'markup_percent',
        'suggested_price',
        'applied_sale_price',
        'batch',
        'expires_at',
        'ncm',
        'purpose',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => ProductPurpose::class,
            'quantity' => 'integer',
            'unit_cost' => 'decimal:4',
            'discount' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'markup_percent' => 'decimal:4',
            'suggested_price' => 'decimal:2',
            'applied_sale_price' => 'decimal:2',
            'expires_at' => 'date',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    /**
     * Custo unitário EFETIVO que entra no custo médio: o desconto do item rateado pelas
     * unidades. Pagar 100 em 5 unidades com 10 de desconto é custo 18, não 20.
     */
    public function effectiveUnitCost(): float
    {
        if ($this->quantity <= 0) {
            return 0.0;
        }

        return round((float) $this->total_cost / $this->quantity, 4);
    }
}
