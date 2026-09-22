<?php

namespace App\Models;

use App\Enums\MessageCampaignStatus;
use App\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Campanha em lote — contrato `docs/gap-simplesvet/specs/17-crm-mensageria-spec.md`.
 * `client_segment_id` é o segmento salvo de 18; `ad_hoc_client_ids` cobre a ação em lote de 25
 * ("estas 12 linhas selecionadas agora", sem salvar segmento) — os dois são mutuamente
 * exclusivos, nunca os dois preenchidos ao mesmo tempo.
 */
class MessageCampaign extends Model
{
    protected $fillable = [
        'organization_id',
        'professional_id',
        'name',
        'channel',
        'message_template_id',
        'client_segment_id',
        'ad_hoc_client_ids',
        'scheduled_for',
        'status',
        'recipients_count',
        'sent_count',
        'failed_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'status' => MessageCampaignStatus::class,
            'ad_hoc_client_ids' => 'array',
            'scheduled_for' => 'datetime',
            'recipients_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
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

    public function segment(): BelongsTo
    {
        return $this->belongsTo(ClientSegment::class, 'client_segment_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(MessageDispatch::class, 'campaign_id');
    }
}
