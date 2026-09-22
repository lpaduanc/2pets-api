<?php

namespace App\Services\Finance;

use App\Enums\CashMovementType;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialNature;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Services\Commercial\CashRegisterService;
use Carbon\CarbonImmutable;

/**
 * Espelha a baixa de uma DESPESA na gaveta física do caixa — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md: "um lançamento manual de despesa
 * paga em dinheiro da gaveta deve gerar as duas coisas: `financial_entries` (despesa) e
 * `cash_register_movements` (saída da gaveta)".
 *
 * Só DESPESA: receita manual em dinheiro é caso raro (venda e fatura já têm seu próprio
 * espelhamento dedicado) e o enum de movimento de caixa não tem um tipo de "recebimento
 * avulso" que não confunda com suprimento de troco.
 */
final class FinancialEntryCashMirror
{
    public function __construct(private readonly CashRegisterService $cashRegisters) {}

    public function mirrorExpense(FinancialEntry $entry, User $user, float $paidAmount, CarbonImmutable $paidAt): void
    {
        if ($entry->nature !== FinancialNature::EXPENSE || $entry->account?->type !== FinancialAccountType::CASH) {
            return;
        }

        $register = $this->cashRegisters->currentFor($user);

        if ($register === null) {
            return;
        }

        $this->cashRegisters->recordMovement(
            $register,
            CashMovementType::WITHDRAWAL,
            $paidAmount,
            $entry->description,
            $user,
            $entry->payment_method_id,
            $entry->account_id,
            $entry,
            $paidAt,
        );
    }
}
