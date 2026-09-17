<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pathology extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'species',
        'category',
        'is_chronic',
    ];

    protected $casts = [
        'is_chronic' => 'boolean',
    ];

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    public function scopeBySpecies($query, string $species)
    {
        return $query->where(function ($q) use ($species) {
            $q->where('species', $species)
                ->orWhereNull('species');
        });
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'ilike', "%{$term}%");
    }
}
