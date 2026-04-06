<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VaccineCatalog extends Model
{
    use HasFactory;

    protected $table = 'vaccine_catalog';

    protected $fillable = [
        'name',
        'species',
        'doses_required',
        'interval_days',
        'booster_interval_days',
        'description',
        'required',
    ];

    protected function casts(): array
    {
        return [
            'doses_required' => 'integer',
            'interval_days' => 'integer',
            'booster_interval_days' => 'integer',
            'required' => 'boolean',
        ];
    }

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    public function scopeBySpecies($query, string $species)
    {
        return $query->where('species', $species);
    }

    public function scopeRequired($query)
    {
        return $query->where('required', true);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'ilike', "%{$term}%");
    }
}
