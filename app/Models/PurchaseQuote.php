<?php

namespace App\Models;

use App\Enums\PurchaseQuoteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Cotação com fornecedores (docs/gap-petflow/PLANO.md, 3.5). */
class PurchaseQuote extends Model
{
    use SoftDeletes;

    public const RESOURCE_RELATIONS = ['items.product', 'suppliers.supplier', 'suppliers.response.items'];

    /** Prazo padrão para os fornecedores responderem. */
    public const DEFAULT_VALID_DAYS = 7;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'number',
        'status',
        'valid_until',
        'notes',
        'sent_at',
        'closed_at',
        'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PurchaseQuoteStatus::class,
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseQuoteItem::class)->orderBy('id');
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(PurchaseQuoteSupplier::class)->orderBy('id');
    }
}
