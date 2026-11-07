<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Área de atendimento (Clínica, Banho e Tosa, Cirurgia, Internação...) — item 21 do backlog
 * gap-simplesvet. Restringe elegibilidade de agendamento (`OrganizationTeamService`), não é
 * obrigatória: profissional sem nenhuma área associada continua atendendo qualquer serviço.
 */
class ServiceArea extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
        'color',
        'active',
        // PLANO 3.7: a área também é zona do leva e traz.
        'delivery_enabled',
        'delivery_rule_type',
        'delivery_ceps',
        'delivery_max_km',
        'delivery_fee',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'delivery_enabled' => 'boolean',
            'delivery_ceps' => 'array',
            'delivery_max_km' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
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

    public function organizationMembers(): BelongsToMany
    {
        return $this->belongsToMany(OrganizationMember::class, 'organization_member_service_areas');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
