<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\ClientCreditPaymentRequest;
use App\Models\ClientCreditInstallment;
use App\Models\CompanyClientAccount;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Finance\ClientCredit\ClientCreditService;
use App\Services\Professional\ProfessionalClientsQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Crediário do cliente (docs/gap-petflow/PLANO.md, 2.2): parcelas, cotação e recebimento. Mesma
 * régua de quem vê o saldo do cliente (`CompanyClientAccountPolicy::view`: quem opera o balcão da
 * organização), cliente da equipe; desconto de encargo com permissão própria (no service).
 */
class ClientCreditController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly ProfessionalClientsQuery $clients,
        private readonly ClientCreditService $credit,
    ) {}

    public function installments(Request $request, int $clientId): JsonResponse
    {
        $this->authorizeFor($request->user(), $clientId);
        $today = now()->startOfDay();

        return response()->json(['data' => $this->credit->installments($request->user(), $clientId)->map(fn (ClientCreditInstallment $installment): array => [
            'id' => $installment->id,
            'plan_id' => $installment->client_credit_plan_id,
            'sale_id' => $installment->plan->sale_id,
            'sale_number' => $installment->plan->sale?->number,
            'number' => $installment->number,
            'installments_count' => $installment->plan->installments_count,
            'due_date' => $installment->due_date->toDateString(),
            'amount' => (string) $installment->amount,
            'paid_principal' => (string) $installment->paid_principal,
            'paid_fees' => (string) $installment->paid_fees,
            'remaining_principal' => $installment->remainingPrincipal()->toString(),
            'status' => $installment->displayStatus($today),
        ])->values()]);
    }

    public function quote(ClientCreditPaymentRequest $request, int $clientId): JsonResponse
    {
        $this->authorizeFor($request->user(), $clientId);

        return response()->json(['data' => $this->credit->quote($request->user(), $clientId, $request->input('date') ?? now()->toDateString())]);
    }

    public function pay(ClientCreditPaymentRequest $request, int $clientId): JsonResponse
    {
        $this->authorizeFor($request->user(), $clientId);
        $payment = $this->credit->pay($request->user(), $clientId, [
            'amount' => (string) $request->input('amount'),
            'payment_method_id' => $request->integer('payment_method_id'),
            'paid_on' => $request->input('paid_on'),
            'fee_discount' => $request->filled('fee_discount') ? (string) $request->input('fee_discount') : null,
        ]);

        return response()->json(['data' => [
            'id' => $payment->id,
            'paid_on' => $payment->paid_on->toDateString(),
            'amount' => (string) $payment->amount,
            'principal_amount' => (string) $payment->principal_amount,
            'fees_amount' => (string) $payment->fees_amount,
            'fee_discount' => (string) $payment->fee_discount,
            'items' => $payment->items->map(fn ($item) => [
                'installment_id' => $item->client_credit_installment_id,
                'principal' => (string) $item->principal,
                'fees' => (string) $item->fees,
                'fee_discount' => (string) $item->fee_discount,
                'sale_receipt_id' => $item->sale_receipt_id,
            ])->values(),
        ], 'message' => 'Crediário recebido.'], 201);
    }

    private function authorizeFor(User $user, int $clientId): void
    {
        Gate::forUser($user)->authorize('view', CompanyClientAccount::class);
        abort_unless($this->clients->queryForAny($this->scope->teamUserIds($user))->whereKey($clientId)->exists(), 404);
    }
}
