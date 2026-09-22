<?php

namespace App\Console\Commands;

use App\DataTransferObjects\Crm\CrmMessageRequest;
use App\Models\MessageAutomation;
use App\Services\Crm\Automation\MessageAutomationTriggerResolverRegistry;
use App\Services\Crm\CrmMessageDispatcher;
use Illuminate\Console\Command;

/**
 * `crm:run-automations` — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`,
 * item 4 do escopo. Agendado a cada 15 min (`routes/console.php`); resolve quem deve receber
 * cada automação ATIVA hoje e chama `CrmMessageDispatcher` (dedup diário e consentimento são
 * responsabilidade dele, não deste comando).
 */
class RunMessageAutomations extends Command
{
    protected $signature = 'crm:run-automations';

    protected $description = 'Resolve os gatilhos de CRM ativos e dispara mensagem para quem é elegível hoje.';

    public function __construct(
        private readonly MessageAutomationTriggerResolverRegistry $registry,
        private readonly CrmMessageDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        MessageAutomation::query()
            ->where('active', true)
            ->with(['template', 'professional'])
            ->chunkById(50, fn ($automations) => $automations->each(fn (MessageAutomation $automation) => $this->runOne($automation)));

        return self::SUCCESS;
    }

    private function runOne(MessageAutomation $automation): void
    {
        $resolver = $this->registry->resolverFor($automation->trigger);

        if ($resolver === null) {
            $this->warn("Automação #{$automation->id}: nenhum resolvedor para o gatilho {$automation->trigger->value}.");

            return;
        }

        $recipients = $resolver->resolve($automation);
        $recipients->each(fn ($recipient) => $this->dispatcher->dispatch(new CrmMessageRequest(
            template: $automation->template,
            client: $recipient->client,
            pet: $recipient->pet,
            placeholders: $recipient->placeholders,
            automation: $automation,
        )));

        $automation->update(['last_run_at' => now()]);
        $this->info("Automação #{$automation->id} ({$automation->trigger->value}): {$recipients->count()} destinatários elegíveis.");
    }
}
