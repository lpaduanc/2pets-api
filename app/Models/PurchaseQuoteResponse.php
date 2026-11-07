<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Proposta de um fornecedor (PLANO 3.5): frete, prazo, condição e preço por item. */
class PurchaseQuoteResponse extends Model
{
    protected $fillable = ['purchase_quote_supplier_id', 'freight', 'delivery_days', 'payment_terms', 'valid_until', 'notes', 'ip_address'];

    protected $hidden = ['ip_address'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['freight' => 'decimal:2', 'delivery_days' => 'integer', 'valid_until' => 'date'];
    }

    public function quoteSupplier(): BelongsTo
    {
        return $this->belongsTo(PurchaseQuoteSupplier::class, 'purchase_quote_supplier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseQuoteResponseItem::class);
    }
}
