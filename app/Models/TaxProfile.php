<?php

namespace App\Models;

use App\Enums\FiscalCfopOperation;
use App\Enums\FiscalCfopScope;
use App\Enums\GoodsOrigin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Perfil tributário (CFOP, CST/CSOSN, origem, alíquotas) — spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §2. Escopo de dono igual ao do resto do comercial
 * (`CommercialScopeResolver`). Escrita só por `TaxProfileService` (troca do padrão é atômica).
 */
class TaxProfile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
        'is_default',
        'origin',
        'icms_csosn',
        'icms_cst',
        'icms_rate',
        'icms_base_reduction_percent',
        'icms_sn_credit_rate',
        'fcp_rate',
        'pis_cst',
        'pis_rate',
        'cofins_cst',
        'cofins_rate',
        'benefit_code',
        'notes',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'active' => 'boolean',
            'origin' => GoodsOrigin::class,
            'icms_rate' => 'decimal:4',
            'icms_base_reduction_percent' => 'decimal:4',
            'icms_sn_credit_rate' => 'decimal:4',
            'fcp_rate' => 'decimal:4',
            'pis_rate' => 'decimal:4',
            'cofins_rate' => 'decimal:4',
        ];
    }

    public function cfops(): HasMany
    {
        // Ordem de cadastro: sem ORDER BY o PostgreSQL devolve as faixas em ordem variável.
        return $this->hasMany(TaxProfileCfop::class)->orderBy('id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function productGroups(): HasMany
    {
        return $this->hasMany(ProductGroup::class);
    }

    /** CFOP configurado para a faixa, ou `null` (quem emite recusa com 422). Exige `cfops`. */
    public function cfopFor(FiscalCfopOperation $operation, FiscalCfopScope $scope): ?string
    {
        return $this->cfops
            ->first(fn (TaxProfileCfop $cfop): bool => $cfop->operation === $operation && $cfop->scope === $scope)
            ?->cfop;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
