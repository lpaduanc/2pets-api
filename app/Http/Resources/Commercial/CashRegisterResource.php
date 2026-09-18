<?php

namespace App\Http\Resources\Commercial;

use App\Models\CashRegister;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashRegister
 */
class CashRegisterResource extends JsonResource
{
    public const RESOURCE_RELATIONS = ['openedBy:id,name', 'closedBy:id,name', 'settledBy:id,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'accepts_movements' => $this->status->acceptsMovements(),

            'opened_by' => $this->whenLoaded('openedBy', fn () => [
                'id' => $this->openedBy->id,
                'name' => $this->openedBy->name,
            ]),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'opening_amount' => (float) $this->opening_amount,

            'closed_by' => $this->whenLoaded('closedBy', fn () => $this->closedBy ? [
                'id' => $this->closedBy->id,
                'name' => $this->closedBy->name,
            ] : null),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'closing_amount' => $this->closing_amount === null ? null : (float) $this->closing_amount,
            'counted_amount' => $this->counted_amount === null ? null : (float) $this->counted_amount,
            'difference' => $this->difference === null ? null : (float) $this->difference,
            'closing_breakdown' => $this->closing_breakdown,

            'settled_by' => $this->whenLoaded('settledBy', fn () => $this->settledBy ? [
                'id' => $this->settledBy->id,
                'name' => $this->settledBy->name,
            ] : null),
            'settled_at' => $this->settled_at?->toIso8601String(),
            'review_reason' => $this->review_reason,
            'notes' => $this->notes,

            // Só quando os movimentos vieram carregados: a listagem de caixas não precisa
            // somar o extrato de cada um, e forçar isso seria N+1 garantido.
            'totals' => $this->when($this->relationLoaded('movements'), fn (): array => [
                'supplies' => $this->totalSupplies(),
                'withdrawals' => $this->totalWithdrawals(),
                'sales' => $this->totalSales(),
                'expected_cash' => $this->expectedCashAmount(),
            ]),

            'movements' => CashRegisterMovementResource::collection($this->whenLoaded('movements')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
