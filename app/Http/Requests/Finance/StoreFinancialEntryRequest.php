<?php

namespace App\Http\Requests\Finance;

use App\Enums\FinancialNature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lançamento manual (receita/despesa) — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md. `installments` > 1 cria uma série
 * com o mesmo `series_id` (ver `FinancialEntryService::create()`).
 */
class StoreFinancialEntryRequest extends FormRequest
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
            'financial_category_id' => ['required', 'integer', 'exists:financial_categories,id'],
            'account_id' => ['nullable', 'integer', 'exists:financial_accounts,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'description' => ['required', 'string', 'max:255'],
            'nature' => ['required', Rule::enum(FinancialNature::class)],
            'due_date' => ['required', 'date'],
            'accrual_date' => ['nullable', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:60'],
            'interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
