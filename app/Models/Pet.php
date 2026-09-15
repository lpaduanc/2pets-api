<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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

    /**
     * A coluna `image_url` guarda CAMINHO RELATIVO ("/storage/pets/x.jpg"), e o
     * host entra aqui, na leitura, a partir da requisicao atual.
     *
     * Antes o controller gravava a URL absoluta ja resolvida
     * (`Storage::disk('public')->url()` -> `APP_URL`), e isso congelava o host
     * DENTRO DO DADO. Quem subisse a foto pelo navegador do proprio servidor
     * gravava "http://localhost:8000/..." — que funciona so ali. No celular,
     * "localhost" e o proprio aparelho, entao a foto do pet simplesmente nao
     * carregava no app; em producao apontaria para a maquina errada do mesmo
     * jeito. Resolvendo na leitura, cada cliente recebe o host pelo qual ELE
     * alcancou a API, sem nada para manter em sincronia.
     *
     * URL absoluta passa intacta: `StorePetRequest` valida `image_url` como
     * `url`, entao um cliente pode legitimamente apontar para uma imagem
     * externa, e reescrever isso quebraria o caso.
     */
    protected function imageUrl(): Attribute
    {
        return Attribute::make(
            get: function (?string $value): ?string {
                if ($value === null || $value === '') {
                    return null;
                }

                if (Str::startsWith($value, ['http://', 'https://'])) {
                    return $value;
                }

                return url(ltrim($value, '/'));
            },
        );
    }

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
