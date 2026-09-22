<?php

namespace App\Models;

use App\Enums\MessageCategory;
use App\Enums\MessageDispatchStatus;
use App\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um envio individual — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`. Sem
 * `updated_at` (só `created_at`, igual a `ConsentLog`): o registro é escrito uma vez pelo
 * `CrmMessageDispatcher` e nunca mais alterado — histórico imutável do que foi tentado.
 */
class MessageDispatch extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'professional_id',
        'client_id',
        'pet_id',
        'channel',
        'category',
        'template_id',
        'automation_id',
        'campaign_id',
        'to_address',
        'body_rendered',
        'status',
        'provider',
        'provider_message_id',
        'cost',
        'sent_at',
        'failed_reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'category' => MessageCategory::class,
            'status' => MessageDispatchStatus::class,
            'cost' => 'decimal:4',
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function pet(): BelongsTo
    {
        return $this->belongsTo(Pet::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(MessageAutomation::class, 'automation_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MessageCampaign::class, 'campaign_id');
    }
}
