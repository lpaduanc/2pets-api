<?php

namespace App\Http\Requests\Finance;

use App\Enums\FinancialCategoryKind;
use App\Enums\FinancialNature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Categoria do plano de contas — contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 */
class StoreFinancialCategoryRequest extends FormRequest
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
            'nature' => [$required, Rule::enum(FinancialNature::class)],
            'kind' => ['nullable', Rule::enum(FinancialCategoryKind::class)],
            'parent_id' => ['nullable', 'integer', 'exists:financial_categories,id'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
            'active' => ['nullable', 'boolean'],
        ];
    }
}
