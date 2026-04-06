<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PetDeworming extends Model
{
    use HasFactory;

    protected $fillable = [
        'pet_id',
        'product_name',
        'applied_date',
        'next_date',
        'weight_at_application',
        'veterinarian_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'applied_date' => 'date',
            'next_date' => 'date',
            'weight_at_application' => 'decimal:2',
        ];
    }

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function veterinarian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'veterinarian_id');
    }

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    public function scopeUpcoming($query)
    {
        return $query->whereNotNull('next_date')
            ->where('next_date', '>=', now())
            ->orderBy('next_date');
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('next_date')
            ->where('next_date', '<', now())
            ->orderBy('next_date');
    }
}
