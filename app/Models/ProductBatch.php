<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lote de produto com `track_batches` — docs/gap-simplesvet/07. `quantity` é o saldo do lote;
 * só `StockService` o altera, na mesma transação do movimento.
 *
 * `legacy_inventory_id` existe só para rastreabilidade/idempotência da migração de `inventories`
 * (docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md, item 5: "um `Inventory` é um
 * lote implícito") — nulo em todo lote criado depois da migração.
 */
class ProductBatch extends Model
{
    protected $fillable = [
        'product_id',
        'batch_code',
        'expires_at',
        'quantity',
        'unit_cost',
        'legacy_inventory_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'quantity' => 'integer',
            'unit_cost' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
