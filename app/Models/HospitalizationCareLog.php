<?php

namespace App\Models;

use App\Enums\HospitalizationCareStatus;
use App\Enums\HospitalizationCareType;
use App\Exceptions\Hospitalization\HospitalizationCareLogImmutableException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Checklist de cuidados da internação — contrato
 * docs/atendimento-veterinario/12-modulo-clinico-internacao.md §4. Log de eventos, não uma
 * grade fixa por turno: cada cuidado realizado (ou não) é um registro pontual com autor e
 * horário. Mesma imutabilidade de `HospitalizationProgressNote` — travada em `booted()`, não
 * só pela ausência de rota de update/destroy (ver o porquê lá).
 *
 * `status = not_done` sem `notes` é rejeitado na validação da API
 * (`StoreHospitalizationCareLogRequest`) — decisão deliberada de registrar a falha com
 * motivo, em vez de deixar "não marcado" ambíguo entre "não foi feito" e "ninguém registrou".
 */
class HospitalizationCareLog extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    public const RESOURCE_RELATIONS = ['author.professional'];

    protected $fillable = [
        'hospitalization_id',
        'author_id',
        'care_type',
        'status',
        'performed_at',
        'notes',
        'prescription_item_id',
    ];

    protected function casts(): array
    {
        return [
            'performed_at' => 'datetime',
            'created_at' => 'datetime',
            'care_type' => HospitalizationCareType::class,
            'status' => HospitalizationCareStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $careLog): void {
            $careLog->created_at ??= now();
            $careLog->performed_at ??= now();
        });

        static::updating(function (): void {
            throw HospitalizationCareLogImmutableException::forUpdate();
        });

        static::deleting(function (): void {
            throw HospitalizationCareLogImmutableException::forDelete();
        });
    }

    public function hospitalization(): BelongsTo
    {
        return $this->belongsTo(Hospitalization::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** Só presente quando `care_type = medication_administration` (§4.1). */
    public function prescriptionItem(): BelongsTo
    {
        return $this->belongsTo(PrescriptionItem::class);
    }
}
