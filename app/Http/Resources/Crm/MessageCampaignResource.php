<?php

namespace App\Http\Resources\Crm;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MessageCampaign */
class MessageCampaignResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'channel' => $this->channel->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'client_segment_id' => $this->client_segment_id,
            'ad_hoc_client_ids' => $this->ad_hoc_client_ids,
            'scheduled_for' => $this->scheduled_for?->toISOString(),
            'recipients_count' => $this->recipients_count,
            'sent_count' => $this->sent_count,
            'failed_count' => $this->failed_count,
            'template' => new MessageTemplateResource($this->whenLoaded('template')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
