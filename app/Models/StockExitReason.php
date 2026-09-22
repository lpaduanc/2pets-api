<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Motivo cadastrável de "outra saída de estoque" (quebra, amostra, uso interno...) — doc 07. */
class StockExitReason extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
        'affects_cost',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'affects_cost' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'reason_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
