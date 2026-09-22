<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ExamRequest
 */
class ExamRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'pet_id' => $this->pet_id,
            'requested_by' => $this->requested_by,
            'clinical_notes' => $this->clinical_notes,
            'exam_types' => ExamTypeResource::collection($this->whenLoaded('examTypes')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
