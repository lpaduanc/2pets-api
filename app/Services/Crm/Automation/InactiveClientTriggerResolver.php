<?php

namespace App\Services\Crm\Automation;

use App\Contracts\MessageAutomationTriggerResolver;
use App\DataTransferObjects\Crm\AutomationRecipient;
use App\Enums\ClientLifecycleStage;
use App\Enums\MessageAutomationTrigger;
use App\Models\ClientRelationshipProfile;
use App\Models\MessageAutomation;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Crm\ClientRelationshipProfileRecalculator;
use Illuminate\Support\Collection;

/**
 * `inactive_client` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. Reusa o
 * estágio de ciclo de vida de 18 (`ClientRelationshipProfile.lifecycle_stage`), alvo fixo em
 * `quiet_3_6m` — o ponto em que o SimplesVet dispara "sentimos sua falta".
 *
 * **Limitação conhecida, documentada em vez de escondida:** não há rastreamento de "acabou de
 * ENTRAR nesta faixa" (exigiria histórico de transição de estágio, fora do escopo desta
 * entrega) — o dedup diário do dispatcher impede reenvio no MESMO dia, mas um cliente na faixa
 * há semanas continua elegível todo dia em que a automação rodar. Mitigação real (throttle por
 * automação/cliente de N dias, não só "hoje") fica registrada como pendência de produto, não
 * resolvida aqui.
 */
final class InactiveClientTriggerResolver implements MessageAutomationTriggerResolver
{
    private const TARGET_STAGE = ClientLifecycleStage::QUIET_3_6M;

    public function __construct(
        private readonly CommercialScopeResolver $scopeResolver,
        private readonly ClientRelationshipProfileRecalculator $recalculator,
    ) {}

    public function supports(MessageAutomationTrigger $trigger): bool
    {
        return $trigger === MessageAutomationTrigger::INACTIVE_CLIENT;
    }

    public function resolve(MessageAutomation $automation): Collection
    {
        $this->recalculator->ensureFreshFor($automation->professional);
        $ownership = $this->scopeResolver->ownershipFor($automation->professional);

        return ClientRelationshipProfile::query()
            ->forCommercialScope($ownership['organization_id'], $ownership['professional_id'])
            ->notArchived()
            ->where('lifecycle_stage', self::TARGET_STAGE->value)
            ->with('client')
            ->get()
            ->filter(fn (ClientRelationshipProfile $profile): bool => $profile->client !== null)
            ->map(fn (ClientRelationshipProfile $profile): AutomationRecipient => new AutomationRecipient(
                client: $profile->client,
                pet: null,
                placeholders: ['client_name' => $profile->client->name],
            ))
            ->values();
    }
}
