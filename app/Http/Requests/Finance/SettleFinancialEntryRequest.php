<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Baixa (total ou parcial) de um lançamento — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md. Multa/juros/desconto são digitados
 * na baixa (MVP); cálculo automático por taxa cadastrada é V2.
 */
class SettleFinancialEntryRequest extends FormRequest
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
            'paid_amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['nullable', 'date'],
            'account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'fine' => ['nullable', 'numeric', 'min:0'],
            'interest' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
