<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\GeneratedDocument
 */
class GeneratedDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_template_id' => $this->document_template_id,
            'pet_id' => $this->pet_id,
            'medical_record_id' => $this->medical_record_id,
            'issued_by' => $this->issued_by,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'body_html' => $this->body_html,
            'signature_type' => $this->signature_type?->value,
            'signed_at' => $this->signed_at?->toIso8601String(),
            'verification_code' => $this->verification_code,
        ];
    }
}
