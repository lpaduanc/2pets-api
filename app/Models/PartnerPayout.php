<?php

namespace App\Models;

use App\Enums\PartnerPayoutStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Repasse a parceiro terceiro (vet volante/diarista sem vínculo CLT) — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md, regra de negócio 7.
 * Conceitualmente diferente de `CommissionRule`/`CommissionSettlement`: sem cálculo
 * automático, só registro e conciliação.
 */
class PartnerPayout extends Model
{
    protected $fillable = [
        'organization_id',
        'professional_id',
        'partner_user_id',
        'description',
        'amount',
        'period_from',
        'period_to',
        'reference_type',
        'reference_id',
        'status',
        'reconciled_at',
        'reconciled_by',
        'payment_method',
        'payment_reference',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'period_from' => 'date',
            'period_to' => 'date',
            'status' => PartnerPayoutStatus::class,
            'reconciled_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_user_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    /** @param  Builder<self>  $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PartnerPayoutStatus::PENDING->value);
    }
}
