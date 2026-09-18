<?php

namespace App\Http\Resources\Commercial;

use App\Models\FinancialAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FinancialAccount
 */
class FinancialAccountResource extends JsonResource
{
    /**
     * Saldo INJETADO pelo controller (`AccountBalanceService::balancesFor`), nunca calculado
     * aqui: Resource que dispara query vira N+1 sem aviso na primeira listagem.
     */
    public function __construct($resource, private readonly ?float $balance = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'is_acquirer' => $this->type->isAcquirer(),
            'bank_code' => $this->bank_code,
            'branch' => $this->branch,
            'branch_digit' => $this->branch_digit,
            'account_number' => $this->account_number,
            'account_digit' => $this->account_digit,
            'allow_quick_entry' => $this->allow_quick_entry,
            'opening_balance' => (float) $this->opening_balance,
            'opening_balance_date' => $this->opening_balance_date?->toDateString(),
            'balance' => $this->balance,
            'active' => $this->active,
        ];
    }
}
