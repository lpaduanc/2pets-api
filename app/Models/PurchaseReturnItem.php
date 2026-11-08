<?php

namespace App\Models;

use App\Support\Money\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Linha da devolução de compra, com os valores congelados pelo rateio acumulado. */
class PurchaseReturnItem extends Model
{
    protected $fillable = [
        'purchase_return_id',
        'purchase_item_id',
        'quantity',
        'unit_cost',
        'products_total',
        'discount_share',
        'ipi_share',
        'icms_st_share',
        'total',
        'stock_value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'products_total' => 'decimal:2',
            'discount_share' => 'decimal:2',
            'ipi_share' => 'decimal:2',
            'icms_st_share' => 'decimal:2',
            'total' => 'decimal:2',
            'stock_value' => 'decimal:2',
        ];
    }

    /** Quanto deste item da compra já voltou ao fornecedor, em todas as devoluções. */
    public static function returnedFor(int $purchaseItemId): Quantity
    {
        return Quantity::of(self::query()->where('purchase_item_id', $purchaseItemId)->sum('quantity'));
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class);
    }
}
