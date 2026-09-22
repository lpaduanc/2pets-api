<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\FinancialEntryResource;
use App\Models\FinancialEntry;
use App\Services\Finance\AccountsPayableService;
use App\Services\Finance\AccountsReceivableService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Contas a pagar / a receber — contrato docs/gap-simplesvet/03-contas-a-pagar-receber-spec.md.
 * Visão sobre `financial_entries` (doc 02), sem tabela própria. Só `OWNER`
 * (`FinancialEntryPolicy::viewAny`, mesma visão financeira consolidada).
 */
class AccountsLedgerController extends Controller
{
    public function __construct(
        private readonly AccountsPayableService $payable,
        private readonly AccountsReceivableService $receivable,
    ) {}

    public function payable(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', FinancialEntry::class);
        $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        $from = CarbonImmutable::parse($request->date('from'));
        $to = CarbonImmutable::parse($request->date('to'));

        return response()->json([
            'summary' => $this->payable->summary($request->user(), $from, $to),
            'data' => FinancialEntryResource::collection(
                $this->payable->list($request->user(), $request->only(['status', 'from', 'to']))->get()
            ),
        ]);
    }

    public function receivable(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', FinancialEntry::class);
        $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);

        $from = CarbonImmutable::parse($request->date('from'));
        $to = CarbonImmutable::parse($request->date('to'));
        $filters = $request->only(['status', 'from', 'to', 'payment_method_id', 'user_id']);

        return response()->json([
            'summary' => $this->receivable->summary($request->user(), $from, $to),
            'by_payment_method' => $this->receivable->receivedByPaymentMethod($request->user(), $from, $to),
            'data' => FinancialEntryResource::collection($this->receivable->list($request->user(), $filters)->get()),
        ]);
    }
}
