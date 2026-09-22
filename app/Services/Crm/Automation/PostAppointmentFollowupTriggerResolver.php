<?php

namespace App\Services\Crm\Automation;

use App\Contracts\MessageAutomationTriggerResolver;
use App\DataTransferObjects\Crm\AutomationRecipient;
use App\Enums\AppointmentStatus;
use App\Enums\MessageAutomationTrigger;
use App\Models\Appointment;
use App\Models\MessageAutomation;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Support\Collection;

/**
 * `post_appointment_followup` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`.
 * `offset_days` positivo é o único sentido que faz sentido aqui (não existe "pós-atendimento
 * antes de acontecer"), mas a conta é a mesma dos outros resolvers para não ter regra especial.
 */
final class PostAppointmentFollowupTriggerResolver implements MessageAutomationTriggerResolver
{
    public function __construct(private readonly CommercialScopeResolver $scopeResolver) {}

    public function supports(MessageAutomationTrigger $trigger): bool
    {
        return $trigger === MessageAutomationTrigger::POST_APPOINTMENT_FOLLOWUP;
    }

    public function resolve(MessageAutomation $automation): Collection
    {
        $teamUserIds = $this->scopeResolver->teamUserIds($automation->professional);
        $targetDate = today()->copy()->subDays($automation->offset_days);

        return Appointment::query()
            ->whereIn('professional_id', $teamUserIds)
            ->where('status', AppointmentStatus::COMPLETED->value)
            ->whereDate('appointment_date', $targetDate)
            ->with(['client', 'pet'])
            ->get()
            ->map(fn (Appointment $appointment): AutomationRecipient => new AutomationRecipient(
                client: $appointment->client,
                pet: $appointment->pet,
                placeholders: ['client_name' => $appointment->client->name, 'pet_name' => $appointment->pet?->name],
            ))
            ->values();
    }
}
