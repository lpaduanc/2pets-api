<?php

namespace App\Services\Crm\Automation;

use App\Contracts\MessageAutomationTriggerResolver;
use App\Enums\MessageAutomationTrigger;

/**
 * Encontra o resolvedor certo para um gatilho — contrato
 * `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. Bind montado em
 * `AppServiceProvider::register()`; novo gatilho = nova classe + uma linha na lista lá, nunca
 * um `match` crescendo dentro de `AutomationRunner`.
 */
final class MessageAutomationTriggerResolverRegistry
{
    /** @param  list<MessageAutomationTriggerResolver>  $resolvers */
    public function __construct(private readonly array $resolvers) {}

    public function resolverFor(MessageAutomationTrigger $trigger): ?MessageAutomationTriggerResolver
    {
        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($trigger)) {
                return $resolver;
            }
        }

        return null;
    }
}
