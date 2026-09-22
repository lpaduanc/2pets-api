<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Crm\ClientRelationshipProfileRecalculator;
use Illuminate\Console\Command;

/**
 * `crm:recalculate-client-profiles` — contrato
 * `docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md`, regra de negócio 4: reforço
 * agendado do cache lazy (`ClientRelationshipProfileRecalculator::ensureFreshFor()`), para
 * ambientes onde o `schedule:work` roda de verdade. Nunca a ÚNICA via de atualização — a leitura
 * sob demanda continua funcionando mesmo se este comando nunca rodar.
 */
class RecalculateClientProfiles extends Command
{
    protected $signature = 'crm:recalculate-client-profiles';

    protected $description = 'Recalcula os perfis de relacionamento de cliente (ciclo de vida, ABC) de toda organização e profissional volante.';

    public function __construct(
        private readonly ClientRelationshipProfileRecalculator $recalculator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->recalculateForOrganizationOwners();
        $this->recalculateForFreelanceProfessionals();

        return self::SUCCESS;
    }

    private function recalculateForOrganizationOwners(): void
    {
        User::query()
            ->whereHas('ownedOrganizations')
            ->each(fn (User $owner) => $this->reportRecalculation($owner, 'organização de '.$owner->name));
    }

    private function recalculateForFreelanceProfessionals(): void
    {
        User::query()
            ->where('role', 'professional')
            ->whereDoesntHave('activeOrganizationMemberships')
            ->each(fn (User $professional) => $this->reportRecalculation($professional, 'vet volante '.$professional->name));
    }

    private function reportRecalculation(User $professional, string $label): void
    {
        $count = $this->recalculator->recalculateForProfessional($professional);

        $this->info("{$label} (#{$professional->id}): {$count} perfis recalculados.");
    }
}
