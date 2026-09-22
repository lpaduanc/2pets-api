<?php

namespace App\Http\Resources\Crm;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MessageAutomation */
class MessageAutomationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'trigger' => $this->trigger->value,
            'trigger_label' => $this->trigger->label(),
            'offset_days' => $this->offset_days,
            'active' => $this->active,
            'last_run_at' => $this->last_run_at?->toISOString(),
            'template' => new MessageTemplateResource($this->whenLoaded('template')),
        ];
    }
}
