<?php

namespace App\Http\Resources\Finance;

use App\Models\FinancialEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FinancialEntry
 */
class FinancialEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'nature' => $this->nature->value,
            'nature_label' => $this->nature->label(),
            'category' => $this->whenLoaded('category', fn () => [
                'id' => $this->category->id,
                'name' => $this->category->name,
            ]),
            'account' => $this->whenLoaded('account', fn () => $this->account ? [
                'id' => $this->account->id,
                'name' => $this->account->name,
            ] : null),
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier ? [
                'id' => $this->supplier->id,
                'legal_name' => $this->supplier->legal_name,
            ] : null),
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => $this->paymentMethod ? [
                'id' => $this->paymentMethod->id,
                'name' => $this->paymentMethod->name,
            ] : null),
            'due_date' => $this->due_date->toDateString(),
            'accrual_date' => $this->accrual_date->toDateString(),
            'amount' => (float) $this->amount,
            'discount' => (float) $this->discount,
            'fine' => (float) $this->fine,
            'interest' => (float) $this->interest,
            'net_amount' => (float) $this->net_amount,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'paid_amount' => $this->paid_amount === null ? null : (float) $this->paid_amount,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_overdue' => $this->isOverdue(),
            'series_id' => $this->series_id,
            'installment_number' => $this->installment_number,
            'installment_total' => $this->installment_total,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
