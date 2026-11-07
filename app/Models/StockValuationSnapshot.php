<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Foto diária do estoque valorizado de um escopo (PLANO 3.6). */
class StockValuationSnapshot extends Model
{
    protected $fillable = ['organization_id', 'professional_id', 'snapshot_date', 'cost_value', 'sale_value', 'product_count'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['snapshot_date' => 'date', 'cost_value' => 'decimal:2', 'sale_value' => 'decimal:2', 'product_count' => 'integer'];
    }
}
