<?php

namespace App\Models;

use App\Enums\AnticipationMode;
use App\Enums\AnticipationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Antecipação de recebíveis confirmada (docs/gap-petflow/PLANO.md, 2.1). */
class ReceivableAnticipation extends Model
{
    protected $fillable = [
        'organization_id',
        'professional_id',
        'account_id',
        'anticipated_at',
        'mode',
        'rate',
        'gross_total',
        'fee_total',
        'net_total',
        'status',
        'financial_entry_id',
        'created_by',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'anticipated_at' => 'date',
            'mode' => AnticipationMode::class,
            'rate' => 'decimal:4',
            'gross_total' => 'decimal:2',
            'fee_total' => 'decimal:2',
            'net_total' => 'decimal:2',
            'status' => AnticipationStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceivableAnticipationItem::class)->orderBy('id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'account_id');
    }

    public function financialEntry(): BelongsTo
    {
        return $this->belongsTo(FinancialEntry::class);
    }
}
