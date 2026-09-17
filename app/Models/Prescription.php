<?php

namespace App\Models;

use App\Enums\PrescriptionKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
    public const RESOURCE_RELATIONS = ['pet.user', 'professional', 'items', 'canceledBy'];

    protected $fillable = [
        'pet_id',
        'professional_id',
        'appointment_id',
        'medical_record_id',
        'prescription_date',
        'valid_until',
        'general_instructions',
        'warnings',
        'is_controlled',
        'standalone_reason',
        'kind',
        'supersedes_id',
    ];

    /**
     * `issued_at`/`canceled_at`/`canceled_reason`/`canceled_by` ficam FORA de `$fillable` de
     * propósito: só `PrescriptionLifecycleService` (issue/cancel) grava essas colunas, nunca
     * um `store()`/`update()` de controller. As 5 colunas preparatórias de assinatura digital
     * (`control_number`, `signature_type`, `signed_at`, `verification_code`, `hash`) também
     * ficam fora — contrato §2: não lidas, não expostas nesta fatia.
     */
    protected $casts = [
        'prescription_date' => 'date',
        'valid_until' => 'date',
        'is_controlled' => 'boolean',
        'issued_at' => 'datetime',
        'canceled_at' => 'datetime',
        'kind' => PrescriptionKind::class,
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

    /** @return HasMany<PrescriptionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class)->orderBy('position');
    }

    public function canceledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'canceled_by');
    }

    /** A prescrição cancelada que esta reemissão corrige. */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** A reemissão que corrigiu esta prescrição, quando houver. */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    /** `null` = ainda editável (contrato §1). */
    public function isEditable(): bool
    {
        return $this->issued_at === null;
    }

    public function isIssued(): bool
    {
        return $this->issued_at !== null;
    }

    public function isCanceled(): bool
    {
        return $this->canceled_at !== null;
    }

    public function isStandalone(): bool
    {
        return $this->medical_record_id === null;
    }

    /**
     * Prescrição de um atendimento ainda `draft`, ou standalone ainda não emitida — ambas
     * "não emitidas" no sentido do contrato §1: somem se o rascunho pai for descartado
     * (`MedicalRecord`) ou são emitidas junto com `finalize()`/`issue()`.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeNotIssued(Builder $query): Builder
    {
        return $query->whereNull('issued_at');
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
