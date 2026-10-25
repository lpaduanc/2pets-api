<?php

namespace App\Http\Requests\Wallet;

use App\Enums\Wallet\ExpenseFrequency;
use App\Services\Wallet\WalletCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /wallet/expenses.
 *
 * `spent_on` vazio vira hoje (é o caso de quase todo lançamento). `repeat` transforma o
 * mesmo formulário em gasto recorrente, sem tela à parte: "Ração, R$ 189,90, repetir todo
 * mês" é um envio só.
 */
class StoreTutorExpenseRequest extends FormRequest
{
    use ValidatesExpenseFields;

    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    protected function prepareForValidation(): void
    {
        $this->inferCategoryFromSubcategory();
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->expenseRules('required'),
            'spent_on' => ['nullable', 'date', 'before_or_equal:'.WalletCalendar::today()->toDateString()],
            'notes' => ['nullable', 'string', 'max:500'],
            'repeat' => ['nullable', Rule::enum(ExpenseFrequency::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->ensureSubcategoryMatchesCategory($v));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...$this->expenseMessages(),
            'spent_on.date' => 'Data inválida.',
            'spent_on.before_or_equal' => 'A data não pode ser no futuro.',
            'notes.max' => 'A observação pode ter até 500 caracteres.',
            'repeat.enum' => 'Frequência inválida.',
        ];
    }
}
