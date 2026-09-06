<?php

namespace App\Http\Resources;

use App\DataTransferObjects\PetHealthSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Roll-up card of one pet: identity fields the card renders plus the health
 * events due in the requested window. Deliberately carries no clinical detail
 * (no batch, manufacturer, notes) — this is a reminder list, not a record.
 *
 * @property-read PetHealthSummary $resource
 */
class PetHealthSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pet = $this->resource->pet;

        return [
            'id' => $pet->id,
            'name' => $pet->name,
            'species' => $pet->species,
            'breed' => $pet->breed,
            'image' => $pet->image_url,
            'overdue_count' => $this->resource->overdueCount(),
            'due_soon_count' => $this->resource->dueSoonCount(),
            'events' => PetHealthEventResource::collection($this->resource->events),
        ];
    }
}
