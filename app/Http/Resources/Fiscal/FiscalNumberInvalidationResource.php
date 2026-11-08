<?php

namespace App\Http\Resources\Fiscal;

use App\Models\FiscalNumberInvalidation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FiscalNumberInvalidation
 */
class FiscalNumberInvalidationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'series' => $this->series,
            'year' => $this->year,
            'environment' => $this->environment?->value,
            'number_from' => $this->number_from,
            'number_to' => $this->number_to,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'protocol' => $this->protocol,
            'provider' => $this->provider,
            'rejection_reason' => $this->rejection_reason,
            'invalidated_at' => $this->invalidated_at?->toIso8601String(),
            'user' => $this->whenLoaded('user', fn (): ?array => $this->user === null ? null : ['id' => $this->user->id, 'name' => $this->user->name]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
