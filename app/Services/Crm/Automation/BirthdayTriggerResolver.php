<?php

namespace App\Services\Crm\Automation;

use App\Contracts\MessageAutomationTriggerResolver;
use App\DataTransferObjects\Crm\AutomationRecipient;
use App\DataTransferObjects\Reports\BirthdayFilters;
use App\Enums\MessageAutomationTrigger;
use App\Models\MessageAutomation;
use App\Models\Pet;
use App\Models\User;
use App\Services\Reports\BirthdayService;
use Illuminate\Support\Collection;

/**
 * `birthday_pet`/`birthday_client` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`.
 * Reusa `BirthdayService` (25) em vez de duplicar a query de dia/mês — mesma fonte de dado dos
 * painéis operacionais.
 */
final class BirthdayTriggerResolver implements MessageAutomationTriggerResolver
{
    public function __construct(private readonly BirthdayService $birthdayService) {}

    public function supports(MessageAutomationTrigger $trigger): bool
    {
        return in_array($trigger, [MessageAutomationTrigger::BIRTHDAY_PET, MessageAutomationTrigger::BIRTHDAY_CLIENT], true);
    }

    public function resolve(MessageAutomation $automation): Collection
    {
        $targetDate = today()->copy()->subDays($automation->offset_days);
        $isPet = $automation->trigger === MessageAutomationTrigger::BIRTHDAY_PET;

        $panel = $this->birthdayService->inPeriod($automation->professional, new BirthdayFilters(
            from: $targetDate,
            to: $targetDate,
            scope: $isPet ? 'pets' : 'clients',
            includeContact: false,
        ));

        return $isPet ? $this->petRecipients($panel['pets']) : $this->clientRecipients($panel['clients']);
    }

    /** @param  Collection<int, array<string, mixed>>  $pets @return Collection<int, AutomationRecipient> */
    private function petRecipients(Collection $pets): Collection
    {
        return $pets->filter(fn (array $item): bool => $item['tutor']['id'] !== null)
            ->map(fn (array $item): AutomationRecipient => new AutomationRecipient(
                client: User::find($item['tutor']['id']),
                pet: Pet::find($item['id']),
                placeholders: ['client_name' => $item['tutor']['name'], 'pet_name' => $item['name']],
            ))
            ->values();
    }

    /** @param  Collection<int, array<string, mixed>>  $clients @return Collection<int, AutomationRecipient> */
    private function clientRecipients(Collection $clients): Collection
    {
        return $clients->map(fn (array $item): AutomationRecipient => new AutomationRecipient(
            client: User::find($item['id']),
            pet: null,
            placeholders: ['client_name' => $item['name']],
        ))->values();
    }
}
