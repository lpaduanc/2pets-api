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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
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
