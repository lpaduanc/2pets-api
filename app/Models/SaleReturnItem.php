<?php

namespace App\Models;

use App\Support\Money\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReturnItem extends Model
{
    protected $fillable = [
        'sale_return_id',
        'sale_item_id',
        'quantity',
        'unit_price',
        'total',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    /**
     * Quanto de um PRODUTO já voltou de uma venda, somando as linhas dela — o valor de volta ao
     * estoque é rateado por produto (`StockService::returningValue`), e a mesma venda pode ter
     * duas linhas do mesmo produto.
     */
    public static function returnedForProduct(int $saleId, int $productId): Quantity
    {
        return Quantity::of(self::query()
            ->whereHas('saleItem', fn ($q) => $q->where('sale_id', $saleId)
                ->where('sellable_type', Product::class)
                ->where('sellable_id', $productId))
            ->sum('quantity'));
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
