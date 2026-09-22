<?php

namespace App\Observers\Organization;

use App\Models\Professional;
use App\Services\Organization\TeamSpecialtyAggregator;

/**
 * Fase 7 do fluxo de agendamento: quando a PRÓPRIA especialidade de um profissional muda,
 * qualquer organização de que ele participa (dono OU membro comum — um cardiologista pode
 * ser membro comum de uma clínica que outra pessoa possui) precisa ressincronizar
 * `professionals.team_specialties`. Convive com `ProfessionalSpecialtyObserver` (mesma
 * classe de gatilho, responsabilidade diferente — aquele sincroniza a pivô de catálogo,
 * este sincroniza o agregado de busca da equipe); Laravel permite múltiplos observers no
 * mesmo model, e o guard `wasChanged('specialties')` nos dois evita laço: atualizar
 * `team_specialties` do DONO não dispara isto de novo, porque só `specialties` (não
 * `team_specialties`) aciona o recálculo.
 */
final class TeamSpecialtyProfessionalObserver
{
    public function __construct(private readonly TeamSpecialtyAggregator $aggregator) {}

    public function created(Professional $professional): void
    {
        $this->sync($professional);
    }

    public function updated(Professional $professional): void
    {
        if (! $professional->wasChanged('specialties')) {
            return;
        }

        $this->sync($professional);
    }

    private function sync(Professional $professional): void
    {
        $user = $professional->user;

        if ($user === null) {
            return;
        }

        foreach ($this->aggregator->organizationsInvolving($user) as $organization) {
            $this->aggregator->syncForOrganization($organization);
        }
    }
}
