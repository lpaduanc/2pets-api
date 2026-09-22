<?php

namespace App\DataTransferObjects\Crm;

use App\Models\MessageAutomation;
use App\Models\MessageCampaign;
use App\Models\MessageTemplate;
use App\Models\Pet;
use App\Models\User;

/**
 * Agrupa os dados de um envio de CRM num objeto só — usado por `CrmMessageDispatcher::dispatch()`
 * (contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`) para não estourar o limite de
 * 4 parâmetros por método.
 */
final readonly class CrmMessageRequest
{
    /** @param  array<string, string|null>  $placeholders */
    public function __construct(
        public MessageTemplate $template,
        public User $client,
        public ?Pet $pet,
        public array $placeholders,
        public ?MessageAutomation $automation = null,
        public ?MessageCampaign $campaign = null,
    ) {}
}
