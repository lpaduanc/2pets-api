<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * NUNCA expõe `path` (caminho interno no disco privado) — mesma regra de
 * `ExamController::toPublicShape()`. Download acontece por rota autorizada dedicada.
 *
 * @mixin \App\Models\MedicalRecordAttachment
 */
class MedicalRecordAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'medical_record_id' => $this->medical_record_id,
            'original_name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'uploaded_by' => $this->uploaded_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
