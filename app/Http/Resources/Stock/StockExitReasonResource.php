<?php

namespace App\Http\Resources\Stock;

use App\Models\StockExitReason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockExitReason
 */
class StockExitReasonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'affects_cost' => $this->affects_cost,
            'active' => $this->active,
        ];
    }
}
