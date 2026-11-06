<?php

namespace App\Http\Requests\Finance;

use App\Rules\ScopedCostCenterExists;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edição de lançamento em aberto — contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 * Parcelamento e natureza não mudam depois de criado; para isso, cancele e lance de novo.
 */
class UpdateFinancialEntryRequest extends FormRequest
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
            'financial_category_id' => ['sometimes', 'integer', 'exists:financial_categories,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'description' => ['sometimes', 'string', 'max:255'],
            'due_date' => ['sometimes', 'date'],
            'accrual_date' => ['sometimes', 'date'],
            'amount' => ['sometimes', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:1000'],
            // PLANO 2.4: rateio por centro de custo. Σ percent = 100,0000 conferido no service
            // (`CostCenterAllocator`), em inteiros de 4 casas.
            'allocations' => ['nullable', 'array', 'max:20'],
            'allocations.*.cost_center_id' => ['required', 'integer', 'distinct', new ScopedCostCenterExists($this->user())],
            'allocations.*.percent' => ['required', 'numeric', 'gt:0', 'max:100'],
        ];
    }
}
