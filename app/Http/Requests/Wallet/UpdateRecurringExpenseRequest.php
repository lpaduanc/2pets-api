<?php

namespace App\Http\Requests\Wallet;

use App\Enums\Wallet\ExpenseFrequency;
use App\Services\Wallet\WalletCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /wallet/recurring/{id} — o que o tutor mexe numa recorrência: valor (a ração
 * aumentou), frequência, pausar/retomar e a próxima data.
 */
class UpdateRecurringExpenseRequest extends FormRequest
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
            'frequency' => ['sometimes', Rule::enum(ExpenseFrequency::class)],
            'next_due_on' => ['sometimes', 'date', 'after_or_equal:'.WalletCalendar::today()->toDateString()],
            'active' => ['sometimes', 'boolean'],
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
            'frequency.enum' => 'Frequência inválida.',
            'next_due_on.after_or_equal' => 'A próxima data não pode ser no passado.',
        ];
    }
}
