<?php

namespace App\Services\Finance\ClientCredit;

use App\Models\ClientCreditInstallment;
use App\Models\ClientCreditPayment;
use App\Models\ClientCreditPlan;
use App\Models\User;
use App\Services\Commercial\Authorization\SalePermissionGate;
use App\Services\Commercial\CommercialScopeResolver;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crediário do cliente (docs/gap-petflow/PLANO.md, 2.2): parcelas, cotação do devido com encargos
 * na data (nunca gravados por job) e recebimento FIFO.
 *
 * Recebimento: encargos e depois principal, da parcela mais antiga para a mais nova. O principal
 * de cada venda vira um RECEBIMENTO NA VENDA (`ClientCreditSettlement`): caixa, conta corrente
 * (o fiado é conciliado pelo `settleDebitWithReceipt` — é o lançamento no extrato), comissão,
 * receita e rentabilidade seguem o fluxo de sempre. Os encargos viram receita financeira.
 * Desconto de encargo exige `finance.credit_fee_discount` no cargo da organização.
 */
final class ClientCreditService
{
    public const FEE_DISCOUNT_PERMISSION = 'finance.credit_fee_discount';

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly ClientCreditCalculator $calculator,
        private readonly ClientCreditSettlement $settlement,
        private readonly SalePermissionGate $permissions,
    ) {}

    /** @return Collection<int, ClientCreditInstallment> parcelas de planos não cancelados, mais antigas primeiro */
    public function installments(User $user, int $clientId, bool $onlyOpen = false, bool $lock = false): Collection
    {
        $plans = $this->scope->scopeQuery(ClientCreditPlan::query(), $user)->where('client_id', $clientId)->where('status', '!=', ClientCreditPlan::CANCELLED)->select('id');

        return ClientCreditInstallment::query()
            ->whereIn('client_credit_plan_id', $plans)
            ->when($onlyOpen, fn ($query) => $query->where('status', ClientCreditInstallment::OPEN))
            ->with('plan.sale')
            ->orderBy('due_date')->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->get();
    }

    /** @return array{date: string, installments: list<array<string, mixed>>, totals: array<string, string>} */
    public function quote(User $user, int $clientId, string $date): array
    {
        $lines = $this->installments($user, $clientId, onlyOpen: true)->map(fn (ClientCreditInstallment $installment): array => [
            'installment_id' => $installment->id,
            'sale_id' => $installment->plan->sale_id,
            // O número que o balcão conhece (o id é interno): sem ele a tela mostrava "#40" na venda nº 7.
            'sale_number' => $installment->plan->sale?->number,
            'number' => $installment->number,
            'installments_count' => $installment->plan->installments_count,
            'due_date' => $installment->due_date->toDateString(),
            'amount' => (string) $installment->amount,
            ...$this->calculator->dueOn($installment->toCalculatorState(), $installment->plan->policy(), $date),
        ])->values()->all();

        return [
            'date' => CarbonImmutable::parse($date)->toDateString(),
            'installments' => $lines,
            'totals' => [
                'principal' => Money::sum(array_column($lines, 'remaining_principal'))->toString(),
                'fees' => Money::sum(array_column($lines, 'fees'))->toString(),
                'total_due' => Money::sum(array_column($lines, 'total_due'))->toString(),
            ],
        ];
    }

    /** @param  array{amount: string, payment_method_id: int, paid_on?: string|null, fee_discount?: string|null}  $data */
    public function pay(User $user, int $clientId, array $data): ClientCreditPayment
    {
        $discount = Money::of($data['fee_discount'] ?? '0');
        abort_if($discount->isPositive() && ! $this->permissions->allowsAcrossScope($user, self::FEE_DISCOUNT_PERMISSION), 403, 'Você não tem permissão para dar desconto nos encargos do crediário.');
        $date = CarbonImmutable::parse($data['paid_on'] ?? \App\Support\BusinessClock::todayString())->toDateString();

        return DB::transaction(function () use ($user, $clientId, $data, $discount, $date): ClientCreditPayment {
            $installments = $this->installments($user, $clientId, onlyOpen: true, lock: true);
            abort_if($installments->isEmpty(), 422, 'Este cliente não tem parcela de crediário em aberto.');
            $allocation = $this->calculator->allocate($installments->map->toCalculatorState()->all(), $installments->first()->plan->policy(), $date, (string) $data['amount'], $discount->toString());

            if (Money::of($allocation['unapplied'])->isPositive() || Money::of($allocation['fee_discount_total'])->isLessThan($discount)) {
                throw ValidationException::withMessages(['amount' => 'O valor (ou o desconto) passa do devido. Consulte a cotação antes de receber.']);
            }

            return $this->settlement->record($user, $clientId, $installments->keyBy('id'), $allocation, (int) $data['payment_method_id'], $date);
        });
    }
}
