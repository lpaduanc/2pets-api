<?php

namespace App\Services\Crm\Automation;

use App\Contracts\MessageAutomationTriggerResolver;
use App\DataTransferObjects\Crm\AutomationRecipient;
use App\Enums\MessageAutomationTrigger;
use App\Models\MessageAutomation;
use App\Models\Pet;
use App\Models\Vaccination;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Professional\ProfessionalClientsQuery;
use Illuminate\Support\Collection;

/**
 * `vaccine_due`/`vaccine_overdue` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`.
 * `offset_days` NEGATIVO = antes do evento (due), POSITIVO = depois (overdue); a mesma conta
 * (`hoje - offset_days`) cobre as duas direções sem `if` separado.
 */
final class VaccineTriggerResolver implements MessageAutomationTriggerResolver
{
    public function __construct(
        private readonly CommercialScopeResolver $scopeResolver,
        private readonly ProfessionalClientsQuery $clientsQuery,
    ) {}

    public function supports(MessageAutomationTrigger $trigger): bool
    {
        return in_array($trigger, [MessageAutomationTrigger::VACCINE_DUE, MessageAutomationTrigger::VACCINE_OVERDUE], true);
    }

    public function resolve(MessageAutomation $automation): Collection
    {
        $petIds = $this->teamPetIds($automation);
        $targetDate = today()->copy()->subDays($automation->offset_days);

        return Vaccination::query()
            ->whereIn('pet_id', $petIds)
            ->latestPerType()
            ->whereDate('next_dose_date', $targetDate)
            ->with('pet.user')
            ->get()
            ->filter(fn (Vaccination $vaccination): bool => $vaccination->pet?->user !== null)
            ->map(fn (Vaccination $vaccination): AutomationRecipient => $this->recipientFor($vaccination))
            ->values();
    }

    /** @return list<int> */
    private function teamPetIds(MessageAutomation $automation): array
    {
        $teamUserIds = $this->scopeResolver->teamUserIds($automation->professional);
        $clientIds = $this->clientsQuery->queryForAny($teamUserIds)->pluck('id');

        return Pet::query()->whereIn('user_id', $clientIds)->pluck('id')->all();
    }

    private function recipientFor(Vaccination $vaccination): AutomationRecipient
    {
        return new AutomationRecipient(
            client: $vaccination->pet->user,
            pet: $vaccination->pet,
            placeholders: ['client_name' => $vaccination->pet->user->name, 'pet_name' => $vaccination->pet->name],
        );
    }
}
