<?php

namespace App\Models;

use App\Enums\CommissionSettlementStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fechamento de comissão de UM funcionário por período — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md.
 *
 * `status = closed`/`paid` é IMUTÁVEL (regra de negócio 4): nenhum valor de item já incluído
 * pode mudar depois — o mesmo princípio de nota fiscal emitida.
 */
class CommissionSettlement extends Model
{
    protected $fillable = [
        'organization_id',
        'professional_id',
        'staff_id',
        'period_from',
        'period_to',
        'received_until',
        'total_amount',
        'status',
        'financial_entry_id',
        'paid_at',
        'payment_method',
        'payment_reference',
        'closed_by',
        'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'received_until' => 'date',
            'total_amount' => 'decimal:2',
            'status' => CommissionSettlementStatus::class,
            'paid_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(OrganizationMember::class, 'staff_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CommissionSettlementItem::class);
    }

    public function isImmutable(): bool
    {
        return $this->status->isImmutable();
    }

    /** @param  Builder<self>  $query */
    public function scopeForStaff(Builder $query, int $staffId): Builder
    {
        return $query->where('staff_id', $staffId);
    }
}
