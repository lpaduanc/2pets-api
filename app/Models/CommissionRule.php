<?php

namespace App\Models;

use App\Enums\CommissionCalculationBase;
use App\Enums\CommissionScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Regra de comissão da clínica ao próprio staff — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md.
 *
 * Não confundir com `App\Models\Commission` (take rate da PLATAFORMA sobre o profissional,
 * fluxo antigo intocado). `staff_id = null` é a regra GERAL da organização.
 */
class CommissionRule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'staff_id',
        'scope',
        'scope_id',
        'percent',
        'fixed_amount',
        'calculation_base',
        'only_when_received',
        'valid_from',
        'valid_to',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => CommissionScope::class,
            'calculation_base' => CommissionCalculationBase::class,
            'percent' => 'decimal:4',
            'fixed_amount' => 'decimal:2',
            'only_when_received' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'active' => 'boolean',
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

    public function logs(): HasMany
    {
        return $this->hasMany(CommissionRuleLog::class);
    }

    public function isValidOn(\DateTimeInterface $date): bool
    {
        if ($this->valid_from !== null && $this->valid_from->gt($date)) {
            return false;
        }

        return $this->valid_to === null || ! $this->valid_to->lt($date);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
