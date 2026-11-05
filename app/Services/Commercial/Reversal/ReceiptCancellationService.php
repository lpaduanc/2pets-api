<?php

namespace App\Services\Commercial\Reversal;

use App\Enums\SaleStatus;
use App\Events\Sales\SaleReceiptCancelled;
use App\Models\CashRegister;
use App\Models\CashRegisterMovement;
use App\Models\Sale;
use App\Models\SaleReceipt;
use App\Models\User;
use App\Services\Commercial\CashRegisterService;
use App\Services\Commercial\SaleClientAccountEffects;
use App\Services\Commercial\SaleService;
use App\Services\Finance\SaleRevenueReversalRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Estorno de UM recebimento (docs/gap-petflow/PLANO.md, 2.3) — reaproveita a unidade do
 * cancelamento da venda (`ReceiptReversalService`: bloqueio de recebível pago/antecipado,
 * recebíveis pendentes cancelados, conta corrente devolvida, `reversed_at`).
 *
 * Caixa: volta no caixa em que o dinheiro entrou se ainda estiver aberto; fechado, no caixa
 * aberto de quem estorna, com a descrição apontando o recebimento e o caixa de origem; sem caixa
 * aberto, 422. Recebimento em conta corrente do cliente não passa por caixa: o saldo consumido
 * volta para a conta dele.
 *
 * Venda: pago recalculado só com os recebimentos que valem; deixa de estar paga → `unpaid` (com
 * outro recebimento valendo) ou `open`, a receita lançada é estornada, a comissão já fechada é
 * marcada como estornada, e o evento recalcula (apaga) a rentabilidade. Estoque e nota fiscal
 * ficam: a venda continua existindo.
 */
final class ReceiptCancellationService
{
    public function __construct(
        private readonly ReceiptReversalService $reversal,
        private readonly CashRegisterService $cashRegisters,
        private readonly SaleService $sales,
        private readonly SaleRevenueReversalRecorder $revenue,
        private readonly CommissionReversal $commissions,
        private readonly SaleClientAccountEffects $clientAccounts,
    ) {}

    public function cancel(Sale $sale, SaleReceipt $receipt, User $user, string $reason): Sale
    {
        $this->assertCancellable($sale, $receipt);
        $register = $this->registerFor($receipt, $user);

        DB::transaction(function () use ($sale, $receipt, $user, $reason, $register): void {
            $wasPaid = $sale->status === SaleStatus::PAID;
            $wasCompleted = $wasPaid || $sale->status === SaleStatus::UNPAID;
            $this->reversal->reverse($sale, $receipt, $user, $register, $this->movementDescription($sale, $receipt, $register));
            $receipt->forceFill(['cancel_reason' => $reason, 'cancelled_by' => $user->id])->save();

            $this->sales->refreshPaidTotals($sale);
            $sale->status = $this->statusAfter($sale, $wasCompleted);
            $sale->save();

            $this->clientAccounts->reconcileAfterReceiptCancellation($sale, $receipt, $user);
            $reversedCommissions = $wasPaid && $sale->status !== SaleStatus::PAID ? $this->settleUnpaid($sale, $user, $reason) : [];
            $this->log($sale, $receipt, $user, $reason, $register, $reversedCommissions);
            SaleReceiptCancelled::dispatch($sale->id);
        });

        return $sale->fresh(Sale::RESOURCE_RELATIONS);
    }

    private function assertCancellable(Sale $sale, SaleReceipt $receipt): void
    {
        abort_unless($receipt->sale_id === $sale->id, 404);
        abort_if($sale->status === SaleStatus::CANCELLED, 422, 'Venda cancelada: o recebimento já foi estornado no cancelamento.');

        if ($receipt->isReversed()) {
            throw ValidationException::withMessages(['receipt' => 'Este recebimento já foi estornado.']);
        }

        $this->reversal->assertReversible($receipt);
    }

    /** `null` só para recebimento em conta corrente do cliente, que não passa por caixa. */
    private function registerFor(SaleReceipt $receipt, User $user): ?CashRegister
    {
        if ($receipt->paymentMethod?->kind->isDeferredToClientAccount()) {
            return null;
        }

        $origin = $this->originRegister($receipt);

        if ($origin?->isOpen()) {
            return $origin;
        }

        return $this->cashRegisters->currentFor($user) ?? throw ValidationException::withMessages([
            'cash_register' => 'Abra um caixa para estornar: o caixa em que este recebimento entrou já foi fechado.',
        ]);
    }

    /** O caixa gravado no recebimento; recebimento antigo, o do movimento que o espelhou. */
    private function originRegister(SaleReceipt $receipt): ?CashRegister
    {
        if ($receipt->cash_register_id !== null) {
            return $receipt->cashRegister;
        }

        $registerId = CashRegisterMovement::query()
            ->where('reference_type', $receipt->sale->getMorphClass())
            ->where('reference_id', $receipt->sale_id)
            ->where('payment_method_id', $receipt->payment_method_id)
            ->where('amount', $receipt->amount)
            ->orderBy('id')
            ->value('cash_register_id');

        return $registerId === null ? null : CashRegister::find($registerId);
    }

    private function movementDescription(Sale $sale, SaleReceipt $receipt, ?CashRegister $register): string
    {
        $origin = $receipt->cash_register_id ?? $register?->id;

        return sprintf('Estorno do recebimento #%d da venda %s%s', $receipt->id, $sale->number ?? $sale->id,
            $origin !== null && $origin !== $register?->id ? " (recebido no caixa #{$origin})" : '');
    }

    /**
     * Venda em andamento (pagamento parcial no PDV) fica no status em que estava. Venda concluída
     * (`paid`/`unpaid`) que perde dinheiro: `unpaid` se ainda sobra recebimento valendo (a prazo),
     * `open` se não sobra nenhum (deixa de ser fiado e volta a ser venda aberta).
     */
    private function statusAfter(Sale $sale, bool $wasCompleted): SaleStatus
    {
        if ($sale->isFullyPaid() && $sale->receipts()->active()->exists()) {
            return SaleStatus::PAID;
        }

        if (! $wasCompleted) {
            return $sale->status;
        }

        return $sale->receipts()->active()->exists() ? SaleStatus::UNPAID : SaleStatus::OPEN;
    }

    /** @return list<array<string, mixed>> */
    private function settleUnpaid(Sale $sale, User $user, string $reason): array
    {
        $this->revenue->reverseForPaymentReversal($sale, $user);

        return $this->commissions->reverseForSale($sale, $reason, $user);
    }

    /** @param  list<array<string, mixed>>  $reversedCommissions */
    private function log(Sale $sale, SaleReceipt $receipt, User $user, string $reason, ?CashRegister $register, array $reversedCommissions): void
    {
        activity('sales')->causedBy($user)->performedOn($receipt)->event('sale.receipt_cancelled')
            ->withProperties([
                'sale_id' => $sale->id,
                'amount' => (string) $receipt->amount,
                'payment_method_id' => $receipt->payment_method_id,
                'reason' => $reason,
                'refund_cash_register_id' => $register?->id,
                'origin_cash_register_id' => $receipt->cash_register_id,
                'sale_status_after' => $sale->status->value,
                'paid_amount_after' => (string) $sale->paid_amount,
                'reversed_commissions' => $reversedCommissions,
            ])
            ->log('Recebimento estornado');
    }
}
