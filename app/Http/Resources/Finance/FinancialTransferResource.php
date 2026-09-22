<?php

namespace App\Http\Resources\Finance;

use App\Models\FinancialTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FinancialTransfer
 */
class FinancialTransferResource extends JsonResource
{
    public const RESOURCE_RELATIONS = ['fromAccount:id,name', 'toAccount:id,name', 'createdBy:id,name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_account' => $this->whenLoaded('fromAccount', fn () => ['id' => $this->fromAccount->id, 'name' => $this->fromAccount->name]),
            'to_account' => $this->whenLoaded('toAccount', fn () => ['id' => $this->toAccount->id, 'name' => $this->toAccount->name]),
            'amount' => (float) $this->amount,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'description' => $this->description,
            'created_by' => $this->whenLoaded('createdBy', fn () => ['id' => $this->createdBy->id, 'name' => $this->createdBy->name]),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
