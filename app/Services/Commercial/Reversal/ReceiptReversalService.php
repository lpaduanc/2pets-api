<?php

namespace App\Services\Commercial\Reversal;

use App\Enums\CashMovementType;
use App\Enums\ReceivableStatus;
use App\Models\CashRegister;
use App\Models\Receivable;
use App\Models\Sale;
use App\Models\SaleReceipt;
use App\Models\User;
use App\Services\Commercial\CashRegisterService;
use App\Services\Commercial\SaleClientAccountEffects;

/**
 * Estorno de UM recebimento — a unidade que o cancelamento da venda usa hoje e que o "Estorno de
 * um recebimento" (docs/gap-petflow/PLANO.md, 2.3) vai reaproveitar:
 *  - recebível da adquirente `anticipated`/`settled` (o dinheiro já caiu ou foi antecipado)
 *    BLOQUEIA com 422: devolver ao portador é chargeback/ajuste manual, não um estorno automático;
 *  - `account_credit`: devolve à conta corrente o saldo consumido (não é dinheiro de gaveta);
 *  - qualquer outra forma: movimento `refund` do valor no caixa informado (sem caixa aberto, o
 *    dinheiro volta por fora e só o cancelamento fica registrado);
 *  - recebíveis `pending` → `cancelled`;
 *  - `reversed_at` carimbado: estornar de novo não faz nada (idempotente).
 * A receita no financeiro é da VENDA, não do recebimento (`SaleRevenueReversalRecorder`).
 */
final class ReceiptReversalService
{
    private const BLOCKING_STATUSES = [ReceivableStatus::ANTICIPATED, ReceivableStatus::SETTLED];

    public function __construct(
        private readonly CashRegisterService $cashRegisters,
        private readonly SaleClientAccountEffects $clientAccounts,
    ) {}

    public function assertReversible(SaleReceipt $receipt): void
    {
        $blocked = Receivable::query()
            ->where('sale_receipt_id', $receipt->id)
            ->whereIn('status', array_map(fn (ReceivableStatus $status): string => $status->value, self::BLOCKING_STATUSES))
            ->exists();

        abort_if($blocked, 422, sprintf(
            'O recebimento de R$ %s já foi pago ou antecipado pela adquirente — estorne com a adquirente e ajuste no financeiro antes de cancelar.',
            number_format((float) $receipt->amount, 2, ',', '.'),
        ));
    }

    public function reverse(Sale $sale, SaleReceipt $receipt, User $user, ?CashRegister $register): void
    {
        if ($receipt->reversed_at !== null) {
            return;
        }

        $this->assertReversible($receipt);
        $this->giveMoneyBack($sale, $receipt, $user, $register);

        Receivable::query()
            ->where('sale_receipt_id', $receipt->id)
            ->pending()
            ->update(['status' => ReceivableStatus::CANCELLED->value]);

        $receipt->forceFill(['reversed_at' => now()])->save();
    }

    private function giveMoneyBack(Sale $sale, SaleReceipt $receipt, User $user, ?CashRegister $register): void
    {
        if ($receipt->paymentMethod?->kind->isDeferredToClientAccount()) {
            $this->clientAccounts->refundForCancelledReceipt($sale, $receipt, $user);

            return;
        }

        if ($register === null) {
            return;
        }

        $this->cashRegisters->recordMovement(
            $register,
            CashMovementType::REFUND,
            (float) $receipt->amount,
            'Estorno da venda '.($sale->number ?? $sale->id),
            $user,
            $receipt->payment_method_id,
            $receipt->account_id,
            $sale,
        );
    }
}
