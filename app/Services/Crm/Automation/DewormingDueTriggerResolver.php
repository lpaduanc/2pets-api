<?php

namespace App\Services\Crm\Automation;

use App\Contracts\MessageAutomationTriggerResolver;
use App\DataTransferObjects\Crm\AutomationRecipient;
use App\Enums\MessageAutomationTrigger;
use App\Models\MessageAutomation;
use App\Models\Pet;
use App\Models\PetDeworming;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Professional\ProfessionalClientsQuery;
use Illuminate\Support\Collection;

/** `deworming_due` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. */
final class DewormingDueTriggerResolver implements MessageAutomationTriggerResolver
{
    public function __construct(
        private readonly CommercialScopeResolver $scopeResolver,
        private readonly ProfessionalClientsQuery $clientsQuery,
    ) {}

    public function supports(MessageAutomationTrigger $trigger): bool
    {
        return $trigger === MessageAutomationTrigger::DEWORMING_DUE;
    }

    public function resolve(MessageAutomation $automation): Collection
    {
        $petIds = $this->teamPetIds($automation);
        $targetDate = today()->copy()->subDays($automation->offset_days);

        return PetDeworming::query()
            ->whereIn('pet_id', $petIds)
            ->latestPerPet()
            ->whereDate('next_date', $targetDate)
            ->with('pet.user')
            ->get()
            ->filter(fn (PetDeworming $deworming): bool => $deworming->pet?->user !== null)
            ->map(fn (PetDeworming $deworming): AutomationRecipient => new AutomationRecipient(
                client: $deworming->pet->user,
                pet: $deworming->pet,
                placeholders: ['client_name' => $deworming->pet->user->name, 'pet_name' => $deworming->pet->name],
            ))
            ->values();
    }

    /** @return list<int> */
    private function teamPetIds(MessageAutomation $automation): array
    {
        $teamUserIds = $this->scopeResolver->teamUserIds($automation->professional);
        $clientIds = $this->clientsQuery->queryForAny($teamUserIds)->pluck('id');

        return Pet::query()->whereIn('user_id', $clientIds)->pluck('id')->all();
    }
}
