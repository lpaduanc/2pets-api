<?php

namespace App\Models;

use App\Enums\StockDirection;
use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Livro-razão append-only do estoque de `products` — docs/gap-simplesvet/07. Criado SÓ por
 * `StockService`; nunca `update()`, nunca `delete()`.
 *
 * `SUM(quantity × sign(direction))` por produto é igual a `products.stock_quantity`.
 */
class StockMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'product_id',
        'batch_id',
        'type',
        'direction',
        'quantity',
        'unit_cost',
        'total_cost',
        'balance_after',
        'reason_id',
        'reference_type',
        'reference_id',
        'occurred_at',
        'user_id',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'direction' => StockDirection::class,
            'quantity' => 'integer',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'balance_after' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(StockExitReason::class, 'reason_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function signedQuantity(): int
    {
        return $this->quantity * $this->direction->sign();
    }
}
