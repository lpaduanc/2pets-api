<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Crediário: o fiado de uma venda parcelado (PLANO 2.2), com a política de encargos congelada.
 * `status`: `active` · `paid` · `cancelled`.
 */
class ClientCreditPlan extends Model
{
    public const ACTIVE = 'active';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id', 'professional_id', 'client_id', 'sale_id', 'total', 'installments_count', 'first_due_date',
        'late_fee_percent', 'monthly_interest_percent', 'grace_days', 'status', 'created_by', 'cancelled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'first_due_date' => 'date',
            'late_fee_percent' => 'decimal:2',
            'monthly_interest_percent' => 'decimal:4',
            'grace_days' => 'integer',
            'installments_count' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    public function installments(): HasMany
    {
        return $this->hasMany(ClientCreditInstallment::class)->orderBy('number');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return array{late_fee_percent: string, monthly_interest_percent: string, grace_days: int} */
    public function policy(): array
    {
        return [
            'late_fee_percent' => (string) $this->late_fee_percent,
            'monthly_interest_percent' => (string) $this->monthly_interest_percent,
            'grace_days' => (int) $this->grace_days,
        ];
    }
}
