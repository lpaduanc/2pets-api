<?php

namespace App\Http\Resources\Crm;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MessageDispatch */
class MessageDispatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel->value,
            'category' => $this->category->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'body_rendered' => $this->body_rendered,
            'sent_at' => $this->sent_at?->toISOString(),
            'failed_reason' => $this->failed_reason,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
