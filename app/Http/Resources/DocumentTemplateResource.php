<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\DocumentTemplate
 */
class DocumentTemplateResource extends JsonResource
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
            'description' => $this->description,
            'kind' => $this->kind?->value,
            'kind_label' => $this->kind?->label(),
            'body_html' => $this->body_html,
            'requires_signature' => (bool) $this->requires_signature,
            'active' => (bool) $this->active,
        ];
    }
}
