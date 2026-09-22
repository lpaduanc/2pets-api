<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Memória "código do item no fornecedor → nosso produto", aprendida a cada compra recebida. */
class SupplierProduct extends Model
{
    protected $fillable = [
        'supplier_id',
        'product_id',
        'supplier_product_code',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
