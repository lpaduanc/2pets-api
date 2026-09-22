<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinancialEntry;
use App\Services\Finance\CashFlowProjectionService;
use App\Services\Finance\IncomeStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * DRE e fluxo de caixa — contrato docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 * Só `OWNER` (`FinancialEntryPolicy::viewAny`, mesma visão financeira consolidada dos
 * lançamentos): não é operação de balcão.
 */
class FinancialReportController extends Controller
{
    public function __construct(
        private readonly IncomeStatementService $incomeStatement,
        private readonly CashFlowProjectionService $cashFlow,
    ) {}

    public function incomeStatement(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', FinancialEntry::class);

        $request->validate([
            'regime' => ['nullable', Rule::in(['cash', 'accrual'])],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);

        $data = $this->incomeStatement->generate(
            $request->user(),
            $request->string('regime', 'accrual')->toString(),
            \Carbon\CarbonImmutable::parse($request->date('from')),
            \Carbon\CarbonImmutable::parse($request->date('to')),
        );

        return response()->json(['data' => $data]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', FinancialEntry::class);

        $request->validate(['months' => ['nullable', 'integer', 'min:1', 'max:24']]);

        $data = $this->cashFlow->project($request->user(), (int) $request->integer('months', 12));

        return response()->json(['data' => $data]);
    }
}
