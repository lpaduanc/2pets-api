<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FoodBrand extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'species_target',
    ];

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeBySpecies($query, string $species)
    {
        return $query->where(function ($q) use ($species) {
            $q->where('species_target', $species)
              ->orWhere('species_target', 'all')
              ->orWhereNull('species_target');
        });
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'ilike', "%{$term}%");
    }
}
