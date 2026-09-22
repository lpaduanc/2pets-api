<?php

namespace App\Contracts;

use App\DataTransferObjects\Crm\AutomationRecipient;
use App\Enums\MessageAutomationTrigger;
use App\Models\MessageAutomation;
use Illuminate\Support\Collection;

/**
 * Um resolvedor por gatilho de automação — contrato
 * `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. Novo gatilho = nova classe
 * implementando esta interface (OCP), nunca mais um `match` crescente no `AutomationRunner`.
 */
interface MessageAutomationTriggerResolver
{
    public function supports(MessageAutomationTrigger $trigger): bool;

    /** @return Collection<int, AutomationRecipient> */
    public function resolve(MessageAutomation $automation): Collection;
}
