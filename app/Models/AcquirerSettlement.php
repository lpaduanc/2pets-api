<?php

namespace App\Models;

use App\Enums\AcquirerSettlementStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Depósito da adquirente na conta da clínica — contrato docs/gap-simplesvet/04,
 * "Conciliação de cartões". Os recebimentos que o compõem ficam em
 * `acquirer_settlement_items`.
 */
class AcquirerSettlement extends Model
{
    use HasFactory;

    /** Centavos de diferença que ainda contam como "fecha" — arredondamento de taxa. */
    public const RECONCILIATION_TOLERANCE = 0.02;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'payment_method_id',
        'deposit_date',
        'description',
        'destination_account_id',
        'gross_amount',
        'fee_amount',
        'net_amount',
        'status',
        'reconciled_at',
        'reconciled_by',
        'divergence_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AcquirerSettlementStatus::class,
            'deposit_date' => 'date',
            'gross_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'reconciled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'destination_account_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AcquirerSettlementItem::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeWithStatus(Builder $query, AcquirerSettlementStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }
}
