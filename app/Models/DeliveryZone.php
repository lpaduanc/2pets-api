<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Zona do leva e traz (docs/gap-petflow/PLANO.md, 3.7): lista de CEPs/faixas OU raio em km a
 * partir da clínica, com a taxa. Tabela própria — não é `service_areas` (setor de atendimento).
 */
class DeliveryZone extends Model
{
    public const RULE_CEP = 'cep';

    public const RULE_DISTANCE = 'distance';

    protected $fillable = ['organization_id', 'professional_id', 'name', 'rule_type', 'ceps', 'max_km', 'fee', 'active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ceps' => 'array', 'max_km' => 'decimal:2', 'fee' => 'decimal:2', 'active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function transports(): HasMany
    {
        return $this->hasMany(PetTransport::class);
    }
}
