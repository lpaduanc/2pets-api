<?php

namespace App\Http\Requests\Wallet;

use App\Services\Wallet\WalletCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** PATCH/PUT /wallet/expenses/{id} — só os campos enviados mudam. */
class UpdateTutorExpenseRequest extends FormRequest
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
            ...$this->expenseRules('sometimes'),
            'spent_on' => ['sometimes', 'date', 'before_or_equal:'.WalletCalendar::today()->toDateString()],
            'notes' => ['nullable', 'string', 'max:500'],
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
        ];
    }
}
