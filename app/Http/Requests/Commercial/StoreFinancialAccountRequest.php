<?php

namespace App\Http\Requests\Commercial;

use App\Enums\FinancialAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Conta bancária/caixa/operadora da clínica — contrato docs/gap-simplesvet/04. Serve store e
 * update.
 */
class StoreFinancialAccountRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::enum(FinancialAccountType::class)],
            'bank_code' => ['nullable', 'string', 'max:10'],
            'branch' => ['nullable', 'string', 'max:20'],
            'branch_digit' => ['nullable', 'string', 'max:2'],
            'account_number' => ['nullable', 'string', 'max:30'],
            'account_digit' => ['nullable', 'string', 'max:2'],
            'allow_quick_entry' => ['nullable', 'boolean'],
            'opening_balance' => ['nullable', 'numeric'],
            'opening_balance_date' => ['nullable', 'date'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
