<?php

namespace App\Http\Requests\Finance;

use App\Enums\DreTreatment;
use App\Enums\FinancialCategoryKind;
use App\Enums\FinancialNature;
use App\Rules\ScopedCostCenterExists;
use App\Services\Finance\CostCenter\CostCenterAllocator;
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
            // DRE gerencial (PLANO 1.7); ausente = `operating` na criação, mantém na edição.
            'dre_treatment' => ['sometimes', Rule::enum(DreTreatment::class)],
            // PLANO 2.4: rateio padrão (`null` remove). Σ = 100,0000 conferido em `withValidator`.
            'default_allocation' => ['sometimes', 'nullable', 'array', 'max:20'],
            'default_allocation.*.cost_center_id' => ['required', 'integer', 'distinct', new ScopedCostCenterExists($this->user())],
            'default_allocation.*.percent' => ['required', 'numeric', 'gt:0', 'max:100'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $validator): void {
            $lines = $this->input('default_allocation');

            if (! is_array($lines) || $lines === [] || $validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                app(CostCenterAllocator::class)->assertValid($lines, 'default_allocation');
            } catch (\Illuminate\Validation\ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $validator->errors()->add($field, $messages[0]);
                }
            }
        });
    }
}
