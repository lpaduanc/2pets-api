<?php

namespace App\Http\Requests\Commercial;

use App\Enums\CommissionCalculationBase;
use App\Enums\CommissionScope;
use App\Enums\CommissionTrigger;
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
            'trigger' => ['sometimes', Rule::enum(CommissionTrigger::class)],
            // Deprecado (PLANO 2.6): aceito só por compatibilidade e traduzido para `trigger`.
            'only_when_received' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Cliente antigo manda só `only_when_received`: vira `trigger` (true → on_receipt,
     * false → on_sale). Mandando os dois, vale `trigger`.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('trigger') || ! $this->filled('only_when_received')) {
            return;
        }

        $received = filter_var($this->input('only_when_received'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($received === null) {
            return;
        }

        $this->merge(['trigger' => $received ? CommissionTrigger::ON_RECEIPT->value : CommissionTrigger::ON_SALE->value]);
    }

    /**
     * `only_when_received` não é mais coluna: sai do que vai para o model (que recusa atributo
     * desconhecido fora de produção, `preventSilentlyDiscardingAttributes`).
     *
     * @param  array-key|null  $key
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated();
        unset($validated['only_when_received']);

        return $key === null ? $validated : data_get($validated, $key, $default);
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
