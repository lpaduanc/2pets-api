<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Inutilização de faixa de numeração — contrato
 * docs/gap-simplesvet/05-emissao-fiscal-nfe-nfce-nfse-spec.md. Só relevante para emissão
 * direta com SEFAZ; não crítica para o MVP (ver spec §Regras de negócio).
 */
class FiscalNumberInvalidation extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'kind',
        'series',
        'number_from',
        'number_to',
        'reason',
        'protocol',
        'invalidated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['invalidated_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
