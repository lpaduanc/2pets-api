<?php

namespace App\Models;

use App\Enums\PetSpecies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImmunizationProductSpecies extends Model
{
    public $timestamps = true;

    protected $table = 'immunization_product_species';

    protected $fillable = [
        'immunization_product_id',
        'species',
    ];

    protected function casts(): array
    {
        return [
            'species' => PetSpecies::class,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ImmunizationProduct::class, 'immunization_product_id');
    }
}
