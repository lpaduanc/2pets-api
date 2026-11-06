<?php

namespace App\Http\Resources\Commercial;

use App\Models\CommissionSettlement;
use App\Support\Money\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CommissionSettlement
 */
class CommissionSettlementResource extends JsonResource
{
    public const RESOURCE_RELATIONS = ['staff.user:id,name', 'items.saleItem', 'items.commissionRule', 'deductions.clawback.saleItem:id,sale_id,description',
        'items.soldPackageSession.soldPackageItem.service:id,name', 'items.soldPackageSession.soldPackage.servicePackage:id,name',
        'deductions.clawback.soldPackageSession.soldPackageItem.service:id,name', 'deductions.clawback.soldPackageSession.soldPackage.servicePackage:id,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_id' => $this->staff_id,
            'staff_name' => $this->whenLoaded('staff', fn () => $this->staff?->user?->name),

            'period_from' => $this->period_from->toDateString(),
            'period_to' => $this->period_to->toDateString(),
            'received_until' => $this->received_until->toDateString(),
            'total_amount' => (float) $this->total_amount,
            // PLANO 2.3: comissão estornada descontada aqui. bruto = total + descontos.
            'deductions_amount' => $this->whenLoaded('deductions', fn () => Money::sum($this->deductions->pluck('amount'))->toString()),
            'gross_amount' => $this->whenLoaded('deductions', fn () => Money::of($this->total_amount)->plus(Money::sum($this->deductions->pluck('amount')))->toString()),
            'deductions' => $this->whenLoaded('deductions', fn () => $this->deductions->map(fn ($deduction) => [
                'id' => $deduction->id,
                'clawback_id' => $deduction->commission_clawback_id,
                'amount' => (string) $deduction->amount,
                'reason' => $deduction->clawback?->reason,
                'sale_item_id' => $deduction->clawback?->sale_item_id,
                'sale_id' => $deduction->clawback?->saleItem?->sale_id,
                'sold_package_session_id' => $deduction->clawback?->sold_package_session_id,
                'description' => $deduction->clawback?->saleItem?->description ?? $deduction->clawback?->soldPackageSession?->description(),
                'reversed_settlement_item_id' => $deduction->clawback?->commission_settlement_item_id,
            ])->values()),

            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_method' => $this->payment_method,
            'payment_reference' => $this->payment_reference,
            'closed_at' => $this->closed_at?->toIso8601String(),

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'sale_item_id' => $item->sale_item_id,
                'sold_package_session_id' => $item->sold_package_session_id,
                'description' => $item->saleItem?->description ?? $item->soldPackageSession?->description(),
                'commission_rule_id' => $item->commission_rule_id,
                'base_amount' => (float) $item->base_amount,
                'commission_amount' => (float) $item->commission_amount,
                // Estornado com o recebimento (PLANO 2.3): fica no histórico, o valor volta como desconto.
                'reversed_at' => $item->reversed_at?->toIso8601String(),
                'reversal_reason' => $item->reversal_reason,
            ])),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
