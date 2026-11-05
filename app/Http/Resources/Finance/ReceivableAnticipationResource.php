<?php

namespace App\Http\Resources\Finance;

use App\Models\ReceivableAnticipation;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Antecipação de recebíveis (PLANO 2.1). Dinheiro em string de 2 casas, taxa com 4.
 *
 * @mixin ReceivableAnticipation
 */
class ReceivableAnticipationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'anticipated_at' => $this->anticipated_at->toDateString(),
            'mode' => $this->mode->value,
            'rate' => (string) $this->rate,
            'account_id' => $this->account_id,
            'account_name' => $this->whenLoaded('account', fn () => $this->account?->name),
            'gross_total' => (string) $this->gross_total,
            'fee_total' => (string) $this->fee_total,
            'net_total' => (string) $this->net_total,
            'effective_cost_percent' => $this->effectiveCostPercent(),
            'financial_entry_id' => $this->financial_entry_id,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'receivable_id' => $item->receivable_id,
                'original_expected_date' => $item->original_expected_date->toDateString(),
                'days' => $item->days,
                'net_amount' => (string) $item->net_amount,
                'fee_amount' => (string) $item->fee_amount,
                'received_amount' => Money::of($item->net_amount)->minus($item->fee_amount)->toString(),
                'installment_number' => $item->relationLoaded('receivable') ? $item->receivable?->installment_number : null,
                'installments_count' => $item->relationLoaded('receivable') ? $item->receivable?->installments_count : null,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
        ];
    }

    private function effectiveCostPercent(): ?string
    {
        $gross = Money::of($this->gross_total);

        return $gross->isPositive() ? (string) Money::of($this->fee_total)->toBigDecimal()->multipliedBy(100)->dividedBy($gross->toBigDecimal(), 2, RoundingMode::HalfUp) : null;
    }
}
