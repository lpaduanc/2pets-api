<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Devolução (parcial) de compra ao fornecedor — spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §3.6. Só estoque; o efeito financeiro é decisão pendente.
 */
class PurchaseReturn extends Model
{
    public const RESOURCE_RELATIONS = ['items.purchaseItem.product', 'purchase.supplier', 'user:id,name', 'fiscalDocuments'];

    protected $fillable = ['organization_id', 'professional_id', 'purchase_id', 'reason', 'user_id', 'total'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['total' => 'decimal:2'];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fiscalDocuments(): HasMany
    {
        return $this->hasMany(FiscalDocument::class);
    }
}
