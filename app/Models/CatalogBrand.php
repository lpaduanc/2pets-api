<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Marca do catálogo global. Separada de `Brand` (que é por organização) porque não tem dono;
 * `slug` único impede "Royal Canin" e "ROYAL CANIN " virarem duas marcas.
 */
class CatalogBrand extends Model
{
    protected $fillable = ['name', 'slug'];

    public function products(): HasMany
    {
        return $this->hasMany(CatalogProduct::class);
    }

    public static function findOrCreateByName(string $name): self
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));

        return self::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
    }
}
