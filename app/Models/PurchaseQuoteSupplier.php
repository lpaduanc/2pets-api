<?php

namespace App\Models;

use App\Enums\PurchaseQuoteSupplierStatus;
use App\Support\BusinessClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Um fornecedor convidado para a cotação, com o link público dele (PLANO 3.5). */
class PurchaseQuoteSupplier extends Model
{
    protected $fillable = [
        'purchase_quote_id',
        'supplier_id',
        'status',
        'valid_until',
        'emailed_at',
        'viewed_at',
        'responded_at',
        'rejected_at',
        'rejection_reason',
    ];

    protected $hidden = ['token_hash', 'token_encrypted'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PurchaseQuoteSupplierStatus::class,
            'valid_until' => 'date',
            'token_encrypted' => 'encrypted',
            'emailed_at' => 'datetime',
            'viewed_at' => 'datetime',
            'responded_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(PurchaseQuote::class, 'purchase_quote_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function response(): HasOne
    {
        return $this->hasOne(PurchaseQuoteResponse::class);
    }

    /** Aguardando e com o prazo (`valid_until`, dia de negócio) já passado → vencida. */
    public function effectiveStatus(): PurchaseQuoteSupplierStatus
    {
        if ($this->status->isAwaiting() && $this->valid_until->toDateString() < BusinessClock::todayString()) {
            return PurchaseQuoteSupplierStatus::EXPIRED;
        }

        return $this->status;
    }
}
