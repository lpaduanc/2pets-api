<?php

namespace App\Models;

use App\Enums\MessageAutomationTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gatilho de negócio → template — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`.
 * `AutomationRunner` (`crm:run-automations`) resolve quem deve receber cada automação ATIVA e
 * chama `CrmMessageDispatcher`; o dedup por cliente/pet/dia mora em `message_dispatches`, não
 * aqui.
 */
class MessageAutomation extends Model
{
    protected $fillable = [
        'organization_id',
        'professional_id',
        'message_template_id',
        'trigger',
        'offset_days',
        'active',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'trigger' => MessageAutomationTrigger::class,
            'offset_days' => 'integer',
            'active' => 'boolean',
            'last_run_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }
}
