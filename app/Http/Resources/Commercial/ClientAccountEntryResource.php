<?php

namespace App\Http\Resources\Commercial;

use App\Models\ClientAccountEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Linha do extrato da conta corrente — contrato docs/gap-simplesvet/11-conta-corrente-do-cliente-spec.md.
 *
 * @mixin ClientAccountEntry
 */
class ClientAccountEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'direction' => $this->direction->value,
            'amount' => (float) $this->amount,
            'balance_after' => (float) $this->balance_after,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'user' => $this->whenLoaded('recordedBy', fn () => [
                'id' => $this->recordedBy->id,
                'name' => $this->recordedBy->name,
            ]),
            'notes' => $this->notes,
        ];
    }
}
