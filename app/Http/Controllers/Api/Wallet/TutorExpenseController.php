<?php

namespace App\Http\Controllers\Api\Wallet;

use App\Enums\Wallet\ExpenseFrequency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreTutorExpenseRequest;
use App\Http\Requests\Wallet\UpdateTutorExpenseRequest;
use App\Models\TutorExpense;
use App\Services\Wallet\RecurringExpenseService;
use App\Services\Wallet\WalletCalendar;
use App\Services\Wallet\WalletEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `wallet/expenses` — gastos que o tutor lança.
 *
 * Gasto de outra pessoa responde 404, não 403: não confirmamos que o id existe (mesma regra
 * de `TutorQuoteController`).
 */
class TutorExpenseController extends Controller
{
    public function __construct(private readonly RecurringExpenseService $recurring) {}

    public function store(StoreTutorExpenseRequest $request): JsonResponse
    {
        $data = $request->validated();

        $expense = DB::transaction(function () use ($request, $data) {
            $expense = TutorExpense::create([
                ...collect($data)->except('repeat')->all(),
                'user_id' => $request->user()->id,
                'spent_on' => $data['spent_on'] ?? WalletCalendar::today()->toDateString(),
            ]);

            if (! empty($data['repeat'])) {
                $this->recurring->startFrom($expense, ExpenseFrequency::from($data['repeat']));
            }

            return $expense;
        });

        return $this->respond($expense, 'Gasto registrado.', 201);
    }

    public function update(UpdateTutorExpenseRequest $request, int $id): JsonResponse
    {
        $expense = $this->find($request, $id);
        $expense->update($request->validated());

        return $this->respond($expense, 'Gasto atualizado.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->find($request, $id)->delete();

        return response()->json(['message' => 'Gasto removido.']);
    }

    /** "Lançar de novo": o mesmo gasto com a data de hoje, sem abrir formulário. */
    public function repeat(Request $request, int $id): JsonResponse
    {
        $copy = $this->find($request, $id)->replicate(['recurring_expense_id']);
        $copy->spent_on = WalletCalendar::today();
        $copy->save();

        return $this->respond($copy, 'Gasto lançado de novo.', 201);
    }

    private function find(Request $request, int $id): TutorExpense
    {
        return TutorExpense::query()->ownedBy($request->user())->findOrFail($id);
    }

    private function respond(TutorExpense $expense, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => WalletEntry::fromExpense($expense->fresh('pet'))->toArray(),
            'message' => $message,
        ], $status);
    }
}
