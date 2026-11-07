<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma abertura de embalagem (PLANO 3.3): o documento dos movimentos `package_open_*`. */
class PackageOpening extends Model
{
    protected $fillable = ['organization_id', 'professional_id', 'product_conversion_id', 'from_product_id', 'to_product_id', 'quantity', 'factor', 'entered_quantity', 'total_cost', 'user_id', 'notes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'factor' => 'decimal:4', 'entered_quantity' => 'decimal:4', 'total_cost' => 'decimal:2'];
    }

    public function fromProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'from_product_id');
    }

    public function toProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'to_product_id');
    }
}
