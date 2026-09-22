<?php

namespace App\Http\Resources\Crm;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MessageTemplate */
class MessageTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'channel' => $this->channel->value,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'subject' => $this->subject,
            'body' => $this->body,
            'whatsapp_template_name' => $this->whatsapp_template_name,
            'active' => $this->active,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
