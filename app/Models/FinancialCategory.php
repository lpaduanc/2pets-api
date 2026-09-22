<?php

namespace App\Models;

use App\Enums\FinancialCategoryKind;
use App\Enums\FinancialNature;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Plano de contas — contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * `kind = group` só agrupa (soma recursiva das filhas na árvore); só `kind = entry` (folha)
 * recebe lançamento — validado em `FinancialEntryService::create()`, não aqui, porque é regra
 * de escrita de OUTRO agregado (o lançamento), e Model não orquestra agregado alheio.
 */
class FinancialCategory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'parent_id',
        'name',
        'nature',
        'kind',
        'is_system',
        'sort_order',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nature' => FinancialNature::class,
            'kind' => FinancialCategoryKind::class,
            'is_system' => 'boolean',
            'sort_order' => 'integer',
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

    public function entries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class);
    }

    public function isGroup(): bool
    {
        return $this->kind === FinancialCategoryKind::GROUP;
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
