<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PetWeightHistory extends Model
{
    use HasFactory;

    protected $table = 'pet_weight_history';

    protected $fillable = [
        'pet_id',
        'weight',
        'measured_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'measured_at' => 'datetime',
        ];
    }

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('measured_at');
    }

    public function scopeInPeriod($query, $from, $to)
    {
        return $query->whereBetween('measured_at', [$from, $to]);
    }
}
