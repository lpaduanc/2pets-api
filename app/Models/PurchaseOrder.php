<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Pedido de compra — anterior à entrada de nota (docs/gap-simplesvet/06). */
class PurchaseOrder extends Model
{
    use SoftDeletes;

    public const RESOURCE_RELATIONS = ['supplier', 'items.product'];

    protected $fillable = [
        'organization_id',
        'professional_id',
        'code',
        'supplier_id',
        'status',
        'expected_at',
        'total',
        'notes',
        'sent_at',
        'cancelled_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'expected_at' => 'date',
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total' => 'decimal:2',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }
}
