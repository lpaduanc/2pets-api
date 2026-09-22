<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catálogo "por que o cliente parou de voltar" — contrato
 * `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`. Mesmo padrão de `ClientOrigin`:
 * `organization_id` nulo = catálogo global.
 */
class ChurnReason extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeVisibleTo(Builder $query, ?int $organizationId): Builder
    {
        return $query->where(function (Builder $scoped) use ($organizationId): void {
            $scoped->whereNull('organization_id');

            if ($organizationId !== null) {
                $scoped->orWhere('organization_id', $organizationId);
            }
        });
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
