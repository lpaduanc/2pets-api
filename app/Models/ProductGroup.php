<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Grupo comercial de produto/serviço — dimensão de BI (doc 20) e portadora do markup padrão
 * herdado por produto novo (doc 08). Árvore rasa via `parent_id`; só folha recebe item, mesma
 * semântica do plano de contas do doc 02.
 */
class ProductGroup extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
        'parent_id',
        'default_markup_percent',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_markup_percent' => 'decimal:4',
            'active' => 'boolean',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Markup efetivo: o do próprio grupo ou, faltando, o do pai. Um subgrupo criado sem
     * markup deve herdar o do grupo acima, não cair em "sem markup" — foi a leitura do
     * SimplesVet, onde o subgrupo é refinamento do pai, não um grupo independente.
     */
    public function effectiveMarkupPercent(): ?float
    {
        if ($this->default_markup_percent !== null) {
            return (float) $this->default_markup_percent;
        }

        return $this->parent?->effectiveMarkupPercent();
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
