<?php

namespace App\Http\Controllers\Api\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\UpdateRecurringExpenseRequest;
use App\Http\Resources\Wallet\RecurringExpenseResource;
use App\Models\TutorRecurringExpense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `wallet/recurring` — gastos que se repetem. A criação acontece pelo próprio lançamento
 * (`POST wallet/expenses` com `repeat`); aqui o tutor só vê, ajusta, pausa ou encerra.
 *
 * Encerrar (`destroy`) não apaga os gastos já lançados: eles aconteceram de verdade. A FK
 * `recurring_expense_id` vira nula e o gasto passa a aparecer como avulso.
 */
class TutorRecurringExpenseController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $recurring = TutorRecurringExpense::query()
            ->ownedBy($request->user())
            ->with('pet')
            ->orderByDesc('active')
            ->orderBy('next_due_on')
            ->get();

        return RecurringExpenseResource::collection($recurring);
    }

    public function update(UpdateRecurringExpenseRequest $request, int $id): JsonResponse
    {
        $recurring = $this->find($request, $id);
        $recurring->update($request->validated());

        return response()->json([
            'data' => new RecurringExpenseResource($recurring->fresh('pet')),
            'message' => $recurring->active ? 'Gasto recorrente atualizado.' : 'Gasto recorrente pausado.',
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->find($request, $id)->delete();

        return response()->json(['message' => 'Gasto recorrente encerrado.']);
    }

    private function find(Request $request, int $id): TutorRecurringExpense
    {
        return TutorRecurringExpense::query()->ownedBy($request->user())->findOrFail($id);
    }
}
