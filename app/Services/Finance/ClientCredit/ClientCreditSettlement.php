<?php

namespace App\Services\Finance\ClientCredit;

use App\Enums\CashMovementType;
use App\Enums\FinancialNature;
use App\Models\ClientCreditInstallment;
use App\Models\ClientCreditPayment;
use App\Models\ClientCreditPlan;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Commercial\CashRegisterService;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\SaleService;
use App\Services\Finance\FinancialCategoryProvisioner;
use App\Services\Finance\FinancialEntryService;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Grava um recebimento de crediário já rateado (PLANO 2.2): atualiza as parcelas, lança o principal
 * de cada venda como recebimento NA VENDA (caixa, conta corrente, receita e comissão pelo fluxo de
 * sempre) e os encargos como receita financeira paga ("Encargos de crediário", `dre_treatment =
 * financial`) com o movimento no caixa de quem recebe. Roda dentro da transação do pagamento.
 */
final class ClientCreditSettlement
{
    public const FEES_CATEGORY = 'Encargos de crediário';

    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly SaleService $sales,
        private readonly CashRegisterService $cashRegisters,
        private readonly FinancialCategoryProvisioner $categories,
        private readonly FinancialEntryService $entries,
    ) {}

    /**
     * @param  Collection<int, ClientCreditInstallment>  $installments  por id
     * @param  array<string, mixed>  $allocation  `ClientCreditCalculator::allocate`
     */
    public function record(User $user, int $clientId, Collection $installments, array $allocation, int $paymentMethodId, string $date): ClientCreditPayment
    {
        $method = $this->scope->scopeQuery(PaymentMethod::query(), $user)->active()->find($paymentMethodId);

        if ($method === null || $method->kind->isDeferredToClientAccount()) {
            throw ValidationException::withMessages(['payment_method_id' => 'Forma de recebimento inválida para o crediário.']);
        }

        $payment = ClientCreditPayment::create([
            ...$this->scope->ownershipFor($user),
            'client_id' => $clientId,
            'payment_method_id' => $method->id,
            'paid_on' => $date,
            'amount' => Money::of($allocation['principal_total'])->plus($allocation['fees_total'])->toString(),
            'principal_amount' => $allocation['principal_total'],
            'fees_amount' => $allocation['fees_total'],
            'fee_discount' => $allocation['fee_discount_total'],
            'created_by' => $user->id,
        ]);
        $receipts = $this->receivePrincipal($user, $installments, $allocation['items'], $method);

        foreach ($allocation['items'] as $item) {
            $this->applyToInstallment($installments[$item['installment_id']], $item, $date);
            $payment->items()->create([
                'client_credit_installment_id' => $item['installment_id'],
                'principal' => $item['principal'],
                'fees' => $item['fees'],
                'fee_discount' => $item['fee_discount'],
                'sale_receipt_id' => $receipts[$installments[$item['installment_id']]->plan->sale_id] ?? null,
            ]);
        }

        $this->receiveFees($user, $payment, $method, Money::of($allocation['fees_total']), $date);
        $this->closePaidPlans($installments);

        return $payment->load('items');
    }

    /**
     * Um recebimento por venda com o principal pago dela.
     *
     * @param  Collection<int, ClientCreditInstallment>  $installments
     * @param  list<array<string, mixed>>  $items
     * @return array<int, int> id do recebimento por venda
     */
    private function receivePrincipal(User $user, Collection $installments, array $items, PaymentMethod $method): array
    {
        $bySale = [];

        foreach ($items as $item) {
            $saleId = $installments[$item['installment_id']]->plan->sale_id;
            $bySale[$saleId] = Money::of($bySale[$saleId] ?? '0')->plus($item['principal']);
        }

        $receipts = [];

        foreach (array_filter($bySale, fn (Money $amount): bool => $amount->isPositive()) as $saleId => $amount) {
            $sale = $installments->first(fn (ClientCreditInstallment $installment): bool => $installment->plan->sale_id === $saleId)->plan->sale;
            $receipts[$saleId] = $this->sales->registerReceipt($sale, $user, ['payment_method_id' => $method->id, 'amount' => $amount->toString(), 'installments' => 1], viaCreditPlan: true)->id;
        }

        return $receipts;
    }

    /** @param  array<string, mixed>  $item */
    private function applyToInstallment(ClientCreditInstallment $installment, array $item, string $date): void
    {
        $paidPrincipal = Money::of($installment->paid_principal)->plus($item['principal']);
        $settled = $item['settles_fees'];

        $installment->update([
            'paid_principal' => $paidPrincipal->toString(),
            'paid_fees' => Money::of($installment->paid_fees)->plus($item['fees'])->toString(),
            // Encargo quitado: a multa não volta e os juros recomeçam desta data. Pago em parte
            // (ou perdoado em parte): fica descontado do próximo cálculo.
            'fees_settled_on' => $settled ? $date : $installment->fees_settled_on?->toDateString(),
            'open_fees_paid' => $settled ? '0' : Money::of($installment->open_fees_paid)->plus($item['fees'])->plus($item['fee_discount'])->toString(),
            'status' => $paidPrincipal->isGreaterThanOrEqualTo($installment->amount) ? ClientCreditInstallment::PAID : ClientCreditInstallment::OPEN,
        ]);
    }

    private function receiveFees(User $user, ClientCreditPayment $payment, PaymentMethod $method, Money $fees, string $date): void
    {
        if (! $fees->isPositive()) {
            return;
        }

        $register = $this->cashRegisters->currentFor($user) ?? throw ValidationException::withMessages(['cash_register' => 'Abra um caixa para receber o crediário.']);
        $category = $this->categories->systemCategory($user, FinancialNature::REVENUE, self::FEES_CATEGORY);
        $entry = $this->entries->create($user, [
            'financial_category_id' => $category->id, 'account_id' => $method->default_account_id,
            'description' => "Encargos de crediário — recebimento #{$payment->id}", 'nature' => FinancialNature::REVENUE->value,
            'due_date' => $date, 'accrual_date' => $date, 'amount' => $fees->toString(), 'payment_method_id' => $method->id,
        ])->first();
        $entry->forceFill(['reference_type' => 'client_credit_payment', 'reference_id' => $payment->id])->save();
        $this->entries->settle($entry, $user, ['paid_amount' => $fees->toString(), 'paid_at' => $date]);
        $this->cashRegisters->recordMovement($register, CashMovementType::SALE_RECEIPT, $fees->toFloat(), "Encargos de crediário — recebimento #{$payment->id}", $user, $method->id, $method->default_account_id, $payment);
        $payment->update(['financial_entry_id' => $entry->id]);
    }

    /** @param  Collection<int, ClientCreditInstallment>  $installments */
    private function closePaidPlans(Collection $installments): void
    {
        $installments->pluck('client_credit_plan_id')->unique()->each(function (int $planId): void {
            if (! ClientCreditInstallment::query()->where('client_credit_plan_id', $planId)->where('status', ClientCreditInstallment::OPEN)->exists()) {
                ClientCreditPlan::whereKey($planId)->update(['status' => ClientCreditPlan::PAID]);
            }
        });
    }
}
