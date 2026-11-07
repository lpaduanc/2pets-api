<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "1 saco (`from`) = `factor` unidades do granel (`to`)" (PLANO 3.3). */
class ProductConversion extends Model
{
    protected $fillable = ['organization_id', 'professional_id', 'from_product_id', 'to_product_id', 'factor'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['factor' => 'decimal:4'];
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
