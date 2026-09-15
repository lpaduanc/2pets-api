<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Specialty extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    public function scopeSearch($query, string $term)
    {
        return $query->where('name', 'ilike', "%{$term}%");
    }

    /**
     * Lado inverso da pivô `professional_specialty`. Serve à pergunta "quem tem esta
     * especialidade", que é a da busca, e é por isso que a migration cria índice próprio em
     * `specialty_id` — no Postgres, FK não cria índice sozinha.
     */
    public function professionals(): BelongsToMany
    {
        return $this->belongsToMany(Professional::class, 'professional_specialty')->withTimestamps();
    }
}
