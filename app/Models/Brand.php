<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Marca/fabricante do produto. Cadastro próprio (e não string em `products`) porque o BI do
 * doc 20 agrupa por marca — texto livre produziria "Royal Canin", "royal canin" e
 * "Royal Canin " como três marcas no mesmo relatório.
 */
class Brand extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
        'active',
    ];

    /**
     * @return array<string, string>
     */
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

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
