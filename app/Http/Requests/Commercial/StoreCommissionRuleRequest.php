<?php

namespace App\Http\Requests\Commercial;

use App\Enums\CommissionCalculationBase;
use App\Enums\CommissionScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Contrato docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md. Serve store e
 * update: no update todo campo vira opcional via `sometimes`.
 */
class StoreCommissionRuleRequest extends FormRequest
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
            'staff_id' => ['nullable', 'integer', 'exists:organization_members,id'],
            'scope' => [$required, Rule::enum(CommissionScope::class)],
            'scope_id' => ['nullable', 'integer'],
            'percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['nullable', 'numeric', 'min:0'],
            'calculation_base' => ['nullable', Rule::enum(CommissionCalculationBase::class)],
            'only_when_received' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * `percent`/`fixed_amount` (pelo menos um) e `scope_id` obrigatório fora de `scope=all` são
     * regras cruzadas — mesmo CHECK do banco, replicado aqui para dar erro 422 legível em vez
     * de estourar a constraint.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $isCreate = $this->isMethod('POST');

            if ($isCreate && $this->input('percent') === null && $this->input('fixed_amount') === null) {
                $validator->errors()->add('percent', 'Informe um percentual ou um valor fixo de comissão.');
            }

            $scope = $this->input('scope');

            if ($isCreate && $scope !== null && $scope !== CommissionScope::ALL->value && ! $this->filled('scope_id')) {
                $validator->errors()->add('scope_id', 'Informe o id do produto/serviço/grupo para este escopo.');
            }
        });
    }
}
