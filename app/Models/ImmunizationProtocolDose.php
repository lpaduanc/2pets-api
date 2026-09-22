<?php

namespace App\Models;

use App\Enums\ImmunizationDoseAnchor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Um nó do grafo de doses de um protocolo — contrato spec 13, regras 3/4. */
class ImmunizationProtocolDose extends Model
{
    protected $fillable = [
        'protocol_id',
        'dose_number',
        'interval_days',
        'depends_on_dose_id',
        'anchor',
        'transitions_to_product_id',
        'min_age_days',
        'max_age_days',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'dose_number' => 'integer',
            'interval_days' => 'integer',
            'anchor' => ImmunizationDoseAnchor::class,
            'min_age_days' => 'integer',
            'max_age_days' => 'integer',
        ];
    }

    public function protocol(): BelongsTo
    {
        return $this->belongsTo(ImmunizationProtocol::class, 'protocol_id');
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'depends_on_dose_id');
    }

    /** @return HasMany<self, $this> */
    public function dependents(): HasMany
    {
        return $this->hasMany(self::class, 'depends_on_dose_id');
    }

    public function transitionsToProduct(): BelongsTo
    {
        return $this->belongsTo(ImmunizationProduct::class, 'transitions_to_product_id');
    }
}
