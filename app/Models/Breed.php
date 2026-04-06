<?php

namespace App\Models;

use App\Enums\PetSpecies;
use App\Enums\SizeCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Breed extends Model
{
    use HasFactory;

    protected $fillable = [
        'species',
        'name',
        'size_category',
        'life_expectancy_years',
    ];

    protected function casts(): array
    {
        return [
            'species' => PetSpecies::class,
            'size_category' => SizeCategory::class,
            'life_expectancy_years' => 'integer',
        ];
    }

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    public function pets(): HasMany
    {
        return $this->hasMany(Pet::class);
    }

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    public function scopeBySpecies($query, PetSpecies $species)
    {
        return $query->where('species', $species);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'ilike', "%{$term}%");
    }
}
