<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Prescription extends Model
{
    /** Receita é documento clínico: `destroy()` marca `deleted_at`, nunca apaga a linha. */
    use SoftDeletes;

    /**
     * Relações exigidas por `PrescriptionResource`. Fica aqui porque `Model::preventLazyLoading()`
     * está ativo fora de produção: qualquer caminho que devolva a Resource sem estes eager loads
     * estoura, e uma lista só evita o N+1 em metade dos endpoints.
     *
     * @var list<string>
     */
    public const RESOURCE_RELATIONS = ['pet.user', 'professional'];

    protected $fillable = [
        'pet_id',
        'professional_id',
        'appointment_id',
        'medical_record_id',
        'prescription_date',
        'valid_until',
        'medications',
        'general_instructions',
        'warnings',
        'is_controlled',
    ];

    protected $casts = [
        'prescription_date' => 'date',
        'valid_until' => 'date',
        'medications' => 'array',
        'is_controlled' => 'boolean',
    ];

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function medicalRecord(): BelongsTo
    {
        return $this->belongsTo(MedicalRecord::class);
    }

    /**
     * Receita sem prazo OU com validade a partir de hoje.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where(function (Builder $scoped): void {
            $scoped->whereNull('valid_until')
                ->orWhere('valid_until', '>=', today());
        });
    }

    /**
     * Complemento exato de `scopeValid()`: só receita com prazo já passado.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('valid_until')
            ->where('valid_until', '<', today());
    }
}
