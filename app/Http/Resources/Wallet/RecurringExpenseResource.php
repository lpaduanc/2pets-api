<?php

namespace App\Http\Resources\Wallet;

use App\Models\TutorRecurringExpense;
use App\Services\Wallet\WalletPetSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TutorRecurringExpense */
class RecurringExpenseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'subcategory' => $this->subcategory?->value,
            'subcategory_label' => $this->subcategory?->label(),
            'description' => $this->description ?? $this->subcategory?->label() ?? $this->category->label(),
            'amount' => (float) $this->amount,
            'payment_method' => $this->payment_method?->value,
            'frequency' => $this->frequency->value,
            'frequency_label' => $this->frequency->label(),
            'next_due_on' => $this->next_due_on->toDateString(),
            'active' => $this->active,
            'pet' => $this->pet ? WalletPetSummary::from($this->pet) : null,
        ];
    }
}
