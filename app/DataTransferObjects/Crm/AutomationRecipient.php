<?php

namespace App\DataTransferObjects\Crm;

use App\Models\Pet;
use App\Models\User;

/**
 * Um destinatário elegível de uma automação, já com os placeholders resolvidos — contrato
 * `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. `AutomationRunner` transforma isto em
 * `CrmMessageRequest` antes de chamar o dispatcher.
 */
final readonly class AutomationRecipient
{
    /** @param  array<string, string|null>  $placeholders */
    public function __construct(
        public User $client,
        public ?Pet $pet,
        public array $placeholders,
    ) {}
}
