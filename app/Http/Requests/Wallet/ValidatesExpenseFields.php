<?php

namespace App\Http\Requests\Wallet;

use App\Enums\Wallet\ExpenseCategory;
use App\Enums\Wallet\ExpensePaymentMethod;
use App\Enums\Wallet\ExpenseSubcategory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Regras comuns a gasto avulso e gasto recorrente.
 *
 * Pensado para o tutor digitar o mínimo: basta mandar a `subcategory` (o toque em "Ração")
 * que a `category` é deduzida dela. Só o valor é realmente obrigatório além disso.
 */
trait ValidatesExpenseFields
{
    /** Teto por lançamento: evita o "1500000" de quem esqueceu a vírgula. */
    private const MAX_AMOUNT = 100000;

    protected function inferCategoryFromSubcategory(): void
    {
        $subcategory = ExpenseSubcategory::tryFrom((string) $this->input('subcategory'));

        if ($subcategory && ! $this->filled('category')) {
            $this->merge(['category' => $subcategory->category()->value]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    protected function expenseRules(string $presence): array
    {
        return [
            'category' => [$presence, Rule::enum(ExpenseCategory::class)],
            'subcategory' => ['nullable', Rule::enum(ExpenseSubcategory::class)],
            'pet_id' => ['nullable', 'integer', Rule::exists('pets', 'id')->where('user_id', $this->user()->id)],
            'amount' => [$presence, 'numeric', 'min:0.01', 'max:'.self::MAX_AMOUNT],
            'description' => ['nullable', 'string', 'max:120'],
            'payment_method' => ['nullable', Rule::enum(ExpensePaymentMethod::class)],
        ];
    }

    /** "Vacina" só pode estar em "Saúde": a combinação errada vira 422, não dado torto. */
    protected function ensureSubcategoryMatchesCategory(Validator $validator): void
    {
        $category = ExpenseCategory::tryFrom((string) $this->input('category'));
        $subcategory = ExpenseSubcategory::tryFrom((string) $this->input('subcategory'));

        if ($category && $subcategory && $subcategory->category() !== $category) {
            $validator->errors()->add('subcategory', 'Essa opção não pertence à categoria escolhida.');
        }
    }

    /** @return array<string, string> */
    protected function expenseMessages(): array
    {
        return [
            'category.required' => 'Escolha o tipo do gasto.',
            'category.enum' => 'Tipo de gasto inválido.',
            'subcategory.enum' => 'Opção de gasto inválida.',
            'pet_id.exists' => 'Escolha um dos seus pets.',
            'amount.required' => 'Informe o valor.',
            'amount.numeric' => 'O valor deve ser um número.',
            'amount.min' => 'O valor deve ser maior que zero.',
            'amount.max' => 'O valor passou de R$ 100.000. Confira a vírgula.',
            'description.max' => 'A descrição pode ter até 120 caracteres.',
            'payment_method.enum' => 'Forma de pagamento inválida.',
        ];
    }
}
