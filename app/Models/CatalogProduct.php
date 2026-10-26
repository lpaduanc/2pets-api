<?php

namespace App\Models;

use App\Enums\CatalogCategory;
use App\Enums\CatalogImageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Item do catálogo global de produtos pet (ver migration `create_catalog_products_table`).
 * Uma linha por VARIAÇÃO vendável (ex.: "Golden Adultos Frango 15 kg" e "… 3 kg" são duas).
 */
class CatalogProduct extends Model
{
    use SoftDeletes;

    /** `all` = item que serve a cão e gato (mesmo EAN nas duas espécies, ex.: comedouro). */
    public const SPECIES = ['dog', 'cat', 'all'];

    protected $fillable = [
        'catalog_brand_id',
        'category',
        'species',
        'line_name',
        'name',
        'variant_label',
        'net_weight_grams',
        'ean',
        'dedup_key',
        'description',
        'attributes',
        'ncm',
        'image_path',
        'image_status',
        'image_checked_at',
        'is_active',
    ];

    protected $appends = ['image_url'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => CatalogCategory::class,
            'image_status' => CatalogImageStatus::class,
            'attributes' => 'array',
            'net_weight_grams' => 'integer',
            'image_checked_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(CatalogBrand::class, 'catalog_brand_id');
    }

    /** Mesmo motivo de `Pet::imageUrl()`: o banco guarda caminho relativo, o host é da requisição. */
    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->image_path ? url(ltrim($this->image_path, '/')) : null,
        );
    }

    /** @param  Builder<self>  $query */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        if (preg_match('/^\d{8,14}$/', $term)) {
            return $query->where('ean', $term);
        }

        return $query->where('name', 'ilike', '%'.Str::of($term)->trim()->replace(' ', '%').'%');
    }
}
