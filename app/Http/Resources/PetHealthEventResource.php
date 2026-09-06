<?php

namespace App\Http\Resources;

use App\DataTransferObjects\PetHealthEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read PetHealthEvent $resource
 */
class PetHealthEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->resource->key(),
            'type' => $this->resource->type->value,
            'reference' => $this->resource->reference,
            'due_date' => $this->resource->dueDate->toDateString(),
            'days_until' => $this->resource->daysUntil,
            'level' => $this->resource->level->value,
        ];
    }
}
