<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PetDeworming extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'pet_id',
        'product_name',
        'applied_date',
        'next_date',
        'weight_at_application',
        'veterinarian_id',
        'inventory_id',
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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
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

    /** Item de estoque debitado por esta aplicação — nulo é o caso normal (ver item 2 do parecer). */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
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
