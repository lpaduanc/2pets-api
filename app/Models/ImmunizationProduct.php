<?php

namespace App\Models;

use App\Enums\ImmunizationGroup;
use App\Enums\PetSpecies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Catálogo unificado de vacina/vermífugo/antiparasitário — contrato
 * docs/gap-simplesvet/specs/13-protocolos-vacinais-spec.md. `organization_id` nulo = catálogo
 * global da plataforma (seedado, não editável via API — ver `OrganizationCatalogGate`).
 */
class ImmunizationProduct extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'group',
        'manufacturer',
        'description',
        'legally_required',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'group' => ImmunizationGroup::class,
            'legally_required' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<ImmunizationProductSpecies, $this> */
    public function speciesLinks(): HasMany
    {
        return $this->hasMany(ImmunizationProductSpecies::class);
    }

    /** @return HasMany<ImmunizationProtocol, $this> */
    public function protocols(): HasMany
    {
        return $this->hasMany(ImmunizationProtocol::class);
    }

    /**
     * @return list<PetSpecies>
     */
    public function species(): array
    {
        return $this->speciesLinks->map(fn (ImmunizationProductSpecies $link): PetSpecies => $link->species)->all();
    }

    public function supportsSpecies(PetSpecies $species): bool
    {
        return in_array($species, $this->species(), true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, ?int $organizationId): Builder
    {
        return $query->where(function (Builder $scoped) use ($organizationId): void {
            $scoped->whereNull('organization_id');

            if ($organizationId !== null) {
                $scoped->orWhere('organization_id', $organizationId);
            }
        });
    }
}
