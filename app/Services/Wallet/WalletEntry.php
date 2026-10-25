<?php

namespace App\Services\Wallet;

use App\Enums\Wallet\ExpenseCategory;
use App\Enums\Wallet\ExpenseSubcategory;
use App\Models\Invoice;
use App\Models\Pet;
use App\Models\TutorExpense;
use Carbon\CarbonImmutable;

/**
 * Uma linha da Carteira, venha ela de onde vier.
 *
 * Junta num formato só o gasto que o tutor lançou (`manual`), o que a recorrência lançou
 * (`recurring`) e a fatura que ele pagou pelo 2pets (`invoice`). Só as duas primeiras são
 * editáveis: fatura paga é registro da clínica, o tutor não altera.
 */
final class WalletEntry
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_RECURRING = 'recurring';

    public const SOURCE_INVOICE = 'invoice';

    private function __construct(
        public readonly string $key,
        public readonly string $source,
        public readonly int $id,
        public readonly ExpenseCategory $category,
        public readonly ?ExpenseSubcategory $subcategory,
        public readonly string $description,
        public readonly float $amount,
        public readonly CarbonImmutable $spentOn,
        public readonly ?Pet $pet,
        public readonly ?string $paymentMethod,
        public readonly ?int $recurringExpenseId,
    ) {}

    public static function fromExpense(TutorExpense $expense): self
    {
        return new self(
            key: 'expense-'.$expense->id,
            source: $expense->recurring_expense_id ? self::SOURCE_RECURRING : self::SOURCE_MANUAL,
            id: $expense->id,
            category: $expense->category,
            subcategory: $expense->subcategory,
            description: $expense->displayDescription(),
            amount: (float) $expense->amount,
            spentOn: $expense->spent_on,
            pet: $expense->pet,
            paymentMethod: $expense->payment_method?->value,
            recurringExpenseId: $expense->recurring_expense_id,
        );
    }

    public static function fromInvoice(Invoice $invoice, ?Pet $pet): self
    {
        $clinic = $invoice->professional?->name;

        return new self(
            key: 'invoice-'.$invoice->id,
            source: self::SOURCE_INVOICE,
            id: $invoice->id,
            category: ExpenseCategory::HEALTH,
            subcategory: null,
            description: $clinic ? "Atendimento · {$clinic}" : 'Atendimento',
            amount: (float) $invoice->total,
            spentOn: CarbonImmutable::parse($invoice->payment_date ?? $invoice->issue_date),
            pet: $pet,
            paymentMethod: $invoice->payment_method,
            recurringExpenseId: null,
        );
    }

    public function isEditable(): bool
    {
        return $this->source !== self::SOURCE_INVOICE;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'id' => $this->id,
            'source' => $this->source,
            'editable' => $this->isEditable(),
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'subcategory' => $this->subcategory?->value,
            'subcategory_label' => $this->subcategory?->label(),
            'description' => $this->description,
            'amount' => round($this->amount, 2),
            'spent_on' => $this->spentOn->toDateString(),
            'pet' => $this->pet ? WalletPetSummary::from($this->pet) : null,
            'payment_method' => $this->paymentMethod,
            'recurring_expense_id' => $this->recurringExpenseId,
        ];
    }
}
