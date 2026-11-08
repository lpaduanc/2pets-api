<?php

namespace App\Models;

use App\Enums\FiscalDocumentKind;
use App\Enums\FiscalEnvironment;
use Illuminate\Database\Eloquent\Model;

/**
 * Próximo número de NF-e/NFC-e por (dono, modelo, série, ambiente) — spec fase4-fiscal §3.7.
 * Só `FiscalNumberSequencer` lê e escreve, sempre com a linha travada.
 */
class FiscalNumberSequence extends Model
{
    protected $fillable = ['organization_id', 'professional_id', 'kind', 'series', 'environment', 'start_number', 'next_number'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FiscalDocumentKind::class,
            'environment' => FiscalEnvironment::class,
            'start_number' => 'integer',
            'next_number' => 'integer',
        ];
    }
}
