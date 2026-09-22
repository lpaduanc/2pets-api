<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Box/gaiola de internação — cadastro configurável por dono (item 23 do backlog
 * gap-simplesvet). O módulo clínico de internação (`Hospitalization`) é quem vincula um
 * atendimento a um box; este model só é o catálogo, não a ocupação em si.
 */
class HospitalizationBox extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
        'capacity',
        'notes',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
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
}
