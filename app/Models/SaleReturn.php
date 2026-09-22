<?php

namespace App\Models;

use App\Enums\RefundMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Devolução de venda — documento próprio ligado à venda de origem (docs/gap-simplesvet/07). */
class SaleReturn extends Model
{
    protected $fillable = [
        'organization_id',
        'professional_id',
        'sale_id',
        'reason',
        'refund_method',
        'total',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'refund_method' => RefundMethod::class,
            'total' => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
