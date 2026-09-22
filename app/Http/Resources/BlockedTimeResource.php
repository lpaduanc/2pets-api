<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockedTimeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'professional_id' => $this->professional_id,
            'organization_id' => $this->organization_id,
            'location_id' => $this->location_id,
            'start_datetime' => $this->start_datetime?->toISOString(),
            'end_datetime' => $this->end_datetime?->toISOString(),
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
            'deleted_by' => $this->whenLoaded('deletedBy', fn () => [
                'id' => $this->deletedBy->id,
                'name' => $this->deletedBy->name,
            ]),
        ];
    }
}
