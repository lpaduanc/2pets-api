<?php

namespace App\Services\Wallet;

use App\Enums\Wallet\ExpenseFrequency;
use App\Models\TutorExpense;
use App\Models\TutorRecurringExpense;
use Carbon\CarbonImmutable;

/**
 * Gastos que se repetem sozinhos.
 *
 * O tutor marca "repetir todo mês" no próprio lançamento; daí em diante o comando diário
 * `wallet:generate-recurring` lança o gasto em cada vencimento, sem ele digitar nada.
 */
class RecurringExpenseService
{
    /**
     * Teto de lançamentos atrasados numa execução. Se o servidor ficou parado, recupera até
     * dois anos de mensalidades; além disso é sinal de dado estranho, não de atraso.
     */
    private const MAX_CATCH_UP = 104;

    /** Cria a recorrência a partir do gasto que o tutor acabou de lançar e liga os dois. */
    public function startFrom(TutorExpense $expense, ExpenseFrequency $frequency): TutorRecurringExpense
    {
        $recurring = TutorRecurringExpense::create([
            'user_id' => $expense->user_id,
            'pet_id' => $expense->pet_id,
            'category' => $expense->category,
            'subcategory' => $expense->subcategory,
            'description' => $expense->description,
            'amount' => $expense->amount,
            'payment_method' => $expense->payment_method,
            'frequency' => $frequency,
            'next_due_on' => $frequency->next($expense->spent_on),
            'active' => true,
        ]);

        $expense->update(['recurring_expense_id' => $recurring->id]);

        return $recurring;
    }

    /** Lança todos os vencimentos até hoje. Devolve quantos gastos foram criados. */
    public function generateDue(CarbonImmutable $today): int
    {
        $created = 0;

        TutorRecurringExpense::query()
            ->due($today)
            ->orderBy('id')
            ->each(function (TutorRecurringExpense $recurring) use ($today, &$created) {
                $created += $this->catchUp($recurring, $today);
            });

        return $created;
    }

    private function catchUp(TutorRecurringExpense $recurring, CarbonImmutable $today): int
    {
        $created = 0;
        $dueOn = $recurring->next_due_on;

        for ($i = 0; $i < self::MAX_CATCH_UP && $dueOn->lessThanOrEqualTo($today); $i++) {
            $created += $this->launch($recurring, $dueOn) ? 1 : 0;
            $dueOn = $recurring->frequency->next($dueOn);
        }

        $recurring->update(['next_due_on' => $dueOn]);

        return $created;
    }

    /** `firstOrCreate` + índice único: reexecutar o comando nunca duplica o gasto do dia. */
    private function launch(TutorRecurringExpense $recurring, CarbonImmutable $dueOn): bool
    {
        $expense = TutorExpense::firstOrCreate(
            ['recurring_expense_id' => $recurring->id, 'spent_on' => $dueOn->toDateString()],
            [
                'user_id' => $recurring->user_id,
                'pet_id' => $recurring->pet_id,
                'category' => $recurring->category,
                'subcategory' => $recurring->subcategory,
                'description' => $recurring->description,
                'amount' => $recurring->amount,
                'payment_method' => $recurring->payment_method,
            ],
        );

        return $expense->wasRecentlyCreated;
    }
}
