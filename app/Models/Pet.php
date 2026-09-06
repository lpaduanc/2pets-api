<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Pet extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'species',
        'breed',
        'breed_id',
        'birth_date',
        'gender',
        'weight',
        'size',
        'color',
        'coat_colors',
        'neutered',
        'neutered_status',
        'microchip_number',
        'blood_type',
        'allergies',
        'food_types',
        'food_brand',
        'dietary_restrictions',
        'food_allergies',
        'food_allergies_other',
        'chronic_diseases',
        'chronic_conditions',
        'surgeries',
        'previous_hospitalizations',
        'current_medications',
        'temperament',
        'behavior_notes',
        'social_with',
        'does_exercise',
        'exercise_types',
        'exercise_frequency',
        'daily_walk',
        'docile_with_strangers',
        'docile_with_animals',
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
        'chronic_conditions' => 'array',
        'allergies' => 'array',
        'current_medications' => 'array',
        'coat_colors' => 'array',
        'food_types' => 'array',
        'dietary_restrictions' => 'array',
        'food_allergies' => 'array',
        'surgeries' => 'array',
        'exercise_types' => 'array',
        'previous_hospitalizations' => 'boolean',
        'daily_walk' => 'boolean',
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

    /**
     * Activity log: capture every fillable column change except bookkeeping.
     * The audit endpoint filters on these logs to show the tutor who altered what.
     */
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function breedRelation(): BelongsTo
    {
        return $this->belongsTo(Breed::class, 'breed_id');
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

    public function surgeryRecords(): HasMany
    {
        return $this->hasMany(Surgery::class);
    }

    public function examRecords(): HasMany
    {
        return $this->hasMany(Exam::class);
    }

    public function hospitalizations(): HasMany
    {
        return $this->hasMany(Hospitalization::class);
    }
}
