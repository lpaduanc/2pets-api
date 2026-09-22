<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma dose esperada (ou já aplicada) do plano do pet — contrato spec 13, regra de negócio 6.
 * `status` NÃO é coluna: sempre derivado por `isApplied()`/`isOverdue()`.
 */
class PetImmunizationDose extends Model
{
    protected $fillable = [
        'plan_id',
        'protocol_dose_id',
        'scheduled_for',
        'applied_at',
        'applied_by',
        'vaccination_id',
        'skipped',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'applied_at' => 'datetime',
            'skipped' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PetImmunizationPlan::class, 'plan_id');
    }

    public function protocolDose(): BelongsTo
    {
        return $this->belongsTo(ImmunizationProtocolDose::class, 'protocol_dose_id');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function vaccination(): BelongsTo
    {
        return $this->belongsTo(Vaccination::class);
    }

    public function isApplied(): bool
    {
        return $this->applied_at !== null;
    }

    /** Vencida: agendada no passado, nunca aplicada, e não pulada — sempre calculado em leitura. */
    public function isOverdue(): bool
    {
        if ($this->isApplied() || $this->skipped) {
            return false;
        }

        return $this->scheduled_for->isPast();
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->skipped => 'skipped',
            $this->isApplied() => 'applied',
            $this->isOverdue() => 'overdue',
            default => 'scheduled',
        };
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('applied_at')->where('skipped', false);
    }
}
