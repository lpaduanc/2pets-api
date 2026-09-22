<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Etiqueta livre por escopo comercial — contrato
 * `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`. MVP: só se liga a `User`
 * (cliente) via `taggables`, mesmo que a tabela pivô já seja polimórfica para não precisar de
 * migration nova quando pet/venda entrarem depois.
 */
class Tag extends Model
{
    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    /** @return MorphToMany<User, $this> */
    public function clients(): MorphToMany
    {
        return $this->morphedByMany(User::class, 'taggable')->withTimestamps();
    }
}
