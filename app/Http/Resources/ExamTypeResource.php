<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ExamType
 */
class ExamTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'name' => $this->name,
            'category' => $this->category?->value,
            'category_label' => $this->category?->label(),
            'presentation_html' => $this->presentation_html,
            'closing_html' => $this->closing_html,
            'preparation_instructions' => $this->preparation_instructions,
            'service_id' => $this->service_id,
            'default_duration_minutes' => $this->default_duration_minutes,
            'active' => (bool) $this->active,
        ];
    }
}
