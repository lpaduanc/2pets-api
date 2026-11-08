<?php

namespace App\Models;

use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalEnvironment;
use App\Enums\FiscalNumberInvalidationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inutilização de faixa de numeração — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md. Com a transmissão própria
 * (docs/gap-petflow/specs/fase4-fiscal-sem-envio.md §0) deixou de ser opcional: toda lacuna de
 * NF-e/NFC-e precisa ser inutilizada. Escrita só por `FiscalNumberInvalidationService`.
 */
class FiscalNumberInvalidation extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'user_id',
        'kind',
        'year',
        'environment',
        'series',
        'number_from',
        'number_to',
        'reason',
        'status',
        'provider',
        'provider_id',
        'rejection_reason',
        'protocol',
        'invalidated_at',
        'xml_path',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FiscalDocumentKind::class,
            'environment' => FiscalEnvironment::class,
            'status' => FiscalNumberInvalidationStatus::class,
            'year' => 'integer',
            'number_from' => 'integer',
            'number_to' => 'integer',
            'invalidated_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Pendente ou homologada — ocupa a faixa (`FiscalNumberInvalidationStatus::blocksRange`).
     *
     * @param  Builder<self>  $query
     */
    public function scopeBlockingRange(Builder $query): Builder
    {
        return $query->whereIn('status', [FiscalNumberInvalidationStatus::PENDING->value, FiscalNumberInvalidationStatus::AUTHORIZED->value]);
    }
}
