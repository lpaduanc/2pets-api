<?php

namespace App\Models;

use App\Enums\AbcClass;
use App\Enums\ClientLifecycleStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha por par (escopo comercial, cliente) — contrato
 * `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`. Recalculada sob demanda por
 * `ClientRelationshipProfileRecalculator` (lazy, `recalculated_at` marca "quão velho está").
 *
 * Nunca criada/editada por Eloquent solto fora do recalculador — os números
 * (`total_spent_*`, `abc_class`, `lifecycle_stage`) são sempre DERIVADOS de `Appointment`/
 * `Invoice`/`Sale`, nunca digitados.
 */
class ClientRelationshipProfile extends Model
{
    /** Cache considerado válido por até 24h — regra de negócio 4 da spec. */
    public const STALE_AFTER_HOURS = 24;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'client_id',
        'client_origin_id',
        'churn_reason_id',
        'first_interaction_at',
        'last_interaction_at',
        'total_spent_365d',
        'total_spent_90d',
        'total_spent_30d',
        'abc_class',
        'abc_position',
        'lifecycle_stage',
        'archived_at',
        'notes',
        'recalculated_at',
    ];

    protected function casts(): array
    {
        return [
            'first_interaction_at' => 'datetime',
            'last_interaction_at' => 'datetime',
            'total_spent_365d' => 'decimal:2',
            'total_spent_90d' => 'decimal:2',
            'total_spent_30d' => 'decimal:2',
            'abc_class' => AbcClass::class,
            'abc_position' => 'integer',
            'lifecycle_stage' => ClientLifecycleStage::class,
            'archived_at' => 'datetime',
            'recalculated_at' => 'datetime',
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

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function clientOrigin(): BelongsTo
    {
        return $this->belongsTo(ClientOrigin::class);
    }

    public function churnReason(): BelongsTo
    {
        return $this->belongsTo(ChurnReason::class);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isStale(): bool
    {
        return $this->recalculated_at === null
            || $this->recalculated_at->lt(now()->subHours(self::STALE_AFTER_HOURS));
    }

    /** @param  Builder<self>  $query */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** @param  Builder<self>  $query */
    public function scopeForCommercialScope(Builder $query, ?int $organizationId, int $professionalId): Builder
    {
        if ($organizationId !== null) {
            return $query->where('organization_id', $organizationId);
        }

        return $query->where('professional_id', $professionalId)->whereNull('organization_id');
    }
}
