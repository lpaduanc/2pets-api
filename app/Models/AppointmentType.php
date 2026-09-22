<?php

namespace App\Models;

use App\Enums\ServiceCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Metadado de agenda por tipo de atendimento (duração default, cor) — contrato
 * docs/gap-simplesvet/specs/14-tipos-atendimento-modelos-prontuario-spec.md. NUNCA decide se
 * um agendamento gera prontuário — quem decide continua sendo
 * `MedicalRecordEncounterResolver` + `ServiceCategory` (regra de negócio 2).
 */
class AppointmentType extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
        'category',
        'default_duration_minutes',
        'color',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ServiceCategory::class,
            'default_duration_minutes' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /** @param  Builder<self>  $query */
    public function scopeOfCategory(Builder $query, ServiceCategory $category): Builder
    {
        return $query->where('category', $category->value);
    }
}
