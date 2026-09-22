<?php

namespace App\Models;

use App\Enums\StockCountStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Inventário (contagem física) — docs/gap-simplesvet/07. Só `StockCountService` o fecha. */
class StockCount extends Model
{
    protected $fillable = [
        'organization_id',
        'professional_id',
        'counted_at',
        'closed_at',
        'responsible_id',
        'product_group_id',
        'status',
        'items_correct',
        'items_adjusted',
        'adjustment_value',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StockCountStatus::class,
            'counted_at' => 'datetime',
            'closed_at' => 'datetime',
            'items_correct' => 'integer',
            'items_adjusted' => 'integer',
            'adjustment_value' => 'decimal:2',
        ];
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    public function isOpen(): bool
    {
        return $this->status === StockCountStatus::OPEN;
    }
}
