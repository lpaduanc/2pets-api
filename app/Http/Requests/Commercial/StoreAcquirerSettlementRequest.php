<?php

namespace App\Http\Requests\Commercial;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cadastro manual de depósito da adquirente — contrato docs/gap-simplesvet/04. Importação
 * automática de extrato é V2; hoje a clínica lança o que recebeu do banco, como no SimplesVet.
 */
class StoreAcquirerSettlementRequest extends FormRequest
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
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'deposit_date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'destination_account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
            'gross_amount' => ['required', 'numeric', 'min:0.01'],
            'fee_amount' => ['nullable', 'numeric', 'min:0'],
            'net_amount' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
