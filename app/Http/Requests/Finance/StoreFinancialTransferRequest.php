<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Transferência entre contas — contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 * Nunca entra na DRE.
 */
class StoreFinancialTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_account_id' => ['required', 'integer', 'exists:financial_accounts,id', 'different:to_account_id'],
            'to_account_id' => ['required', 'integer', 'exists:financial_accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'occurred_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
