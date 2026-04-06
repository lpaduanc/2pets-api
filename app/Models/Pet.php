<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Pet extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'species',
        'breed',
        'birth_date',
        'gender',
        'weight',
        'color',
        'neutered',
        'blood_type',
        'allergies',
        'chronic_diseases',
        'current_medications',
        'temperament',
        'behavior_notes',
        'social_with',
        'notes',
        'image_url',
        'public_id',
        'is_lost',
        'lost_alert_message',
        'lost_since',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'weight' => 'decimal:2',
        'neutered' => 'boolean',
        'temperament' => 'array',
        'social_with' => 'array',
        'chronic_diseases' => 'array',
        'allergies' => 'array',
        'current_medications' => 'array',
        'is_lost' => 'boolean',
        'lost_since' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($pet) {
            if (empty($pet->public_id)) {
                $pet->public_id = Str::uuid()->toString();
            }
        });
    }

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vaccinations(): HasMany
    {
        return $this->hasMany(\App\Models\Vaccination::class);
    }

    public function dewormings(): HasMany
    {
        return $this->hasMany(PetDeworming::class);
    }

    public function medications(): HasMany
    {
        return $this->hasMany(PetMedication::class);
    }

    public function weightHistory(): HasMany
    {
        return $this->hasMany(PetWeightHistory::class);
    }

    public function vetAccesses(): HasMany
    {
        return $this->hasMany(PetVetAccess::class);
    }
}
