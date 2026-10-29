<?php

namespace App\Models;

use App\Enums\ProductPurpose;
use App\Services\Stock\StockValuation;
use App\Support\Money\Decimal;
use Brick\Math\RoundingMode;
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
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:2',
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
     * Custo unitário EFETIVO: o desconto do item rateado pelas unidades. Pagar 100 em 5 unidades
     * com 10 de desconto é custo 18, não 20. Só para exibir e para o último custo — o estoque
     * recebe o `total_cost` exato da linha (`PurchaseService::receiveItem`), não este × quantidade.
     */
    public function effectiveUnitCost(): string
    {
        $quantity = Decimal::of($this->quantity);

        if (! $quantity->isPositive()) {
            return '0';
        }

        return (string) Decimal::of($this->total_cost)->dividedBy($quantity, StockValuation::COST_SCALE, RoundingMode::HalfUp);
    }
}
