<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Models\FinancialEntry;
use App\Services\Commercial\Authorization\SalePermissionGate;
use App\Services\Finance\CashFlowProjectionService;
use App\Services\Finance\IncomeStatementService;
use App\Services\Finance\Managerial\ManagerialIncomeStatementService;
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
        private readonly ManagerialIncomeStatementService $managerial,
        private readonly SalePermissionGate $permissions,
    ) {}

    public function incomeStatement(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', FinancialEntry::class);

        $request->validate([
            'regime' => ['nullable', Rule::in(['cash', 'accrual', ManagerialIncomeStatementService::REGIME])],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            // PLANO 2.4 (só no regime gerencial): um centro (id ou `unallocated`) e/ou a quebra por centro.
            'cost_center_id' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                if ($value === 'unallocated') {
                    return;
                }

                (new \App\Rules\ScopedCostCenterExists($request->user(), onlyActive: false))->validate($attribute, $value, $fail);
            }],
            'breakdown' => ['nullable', Rule::in(['cost_center'])],
        ]);

        $regime = $request->string('regime', 'accrual')->toString();
        $from = \Carbon\CarbonImmutable::parse($request->date('from'));
        $to = \Carbon\CarbonImmutable::parse($request->date('to'));

        if ($regime === ManagerialIncomeStatementService::REGIME) {
            // Gerencial mostra custo e margem (PLANO 1.7): além de `reports.dre.view`, a mesma
            // `reports.profitability` da rentabilidade, pelo cargo em toda organização do escopo.
            abort_unless(
                $this->permissions->allowsAcrossScope($request->user(), SalePermissionGate::PROFITABILITY),
                403,
                'A DRE gerencial mostra custo e margem: é preciso a permissão de rentabilidade (reports.profitability).',
            );

            return response()->json(['data' => $this->managerial->generate(
                $request->user(), $from, $to,
                $request->filled('cost_center_id') ? $request->string('cost_center_id')->toString() : null,
                $request->input('breakdown') === 'cost_center',
            )]);
        }

        return response()->json(['data' => $this->incomeStatement->generate($request->user(), $regime, $from, $to)]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', FinancialEntry::class);

        $request->validate(['months' => ['nullable', 'integer', 'min:1', 'max:24']]);

        $data = $this->cashFlow->project($request->user(), (int) $request->integer('months', 12));

        return response()->json(['data' => $data]);
    }
}
