<?php

namespace App\Services\Commercial;

use App\Models\CashRegisterMovement;
use App\Models\FinancialAccount;
use Illuminate\Support\Carbon;

/**
 * Saldo de conta — contrato docs/gap-simplesvet/04-contas-bancarias-conciliacao-cartoes.md.
 *
 * O saldo é SEMPRE derivado, nunca uma coluna: saldo inicial + movimentos até a data de corte.
 * Uma coluna `balance` mutável desanda no primeiro movimento que falhar no meio de uma
 * transação e nunca mais bate com o extrato — ver a nota da migration.
 *
 * Fonte dos movimentos hoje: `cash_register_movements`, que é o único livro-razão financeiro
 * implementado. Quando o doc 02 (`financial_entries`) entrar, a soma ganha a segunda perna
 * AQUI, num único lugar, e nenhuma tela precisa mudar.
 *
 * `sale_receipts` NÃO é somada de propósito: todo recebimento que entra em caixa já vira um
 * `cash_register_movements` de tipo `sale_receipt` (`SaleService::mirrorReceiptInCashRegister`).
 * Somar as duas contaria o mesmo dinheiro duas vezes.
 */
final class AccountBalanceService
{
    /**
     * Saldo da conta na data informada (inclusive). Sem data, saldo de agora.
     *
     * `opening_balance_date` funciona como corte: movimento anterior à data do saldo inicial
     * fica de fora, porque o saldo inicial JÁ o contém. Sem esse cuidado, importar um histórico
     * numa conta que já tinha saldo inicial dobraria o passado.
     */
    public function balance(FinancialAccount $account, ?\DateTimeInterface $at = null): float
    {
        $cutoff = $at === null ? Carbon::now() : Carbon::instance($at)->endOfDay();

        $query = CashRegisterMovement::query()
            ->where('account_id', $account->id)
            ->where('occurred_at', '<=', $cutoff);

        if ($account->opening_balance_date !== null) {
            $query->where('occurred_at', '>=', $account->opening_balance_date->startOfDay());
        }

        $net = $query->with('paymentMethod')
            ->get()
            ->sum(fn (CashRegisterMovement $movement): float => $movement->signedAmount());

        return round((float) $account->opening_balance + $net, 2);
    }

    /**
     * Saldo de várias contas de uma vez, para a listagem com a coluna "Saldo". Evita o N+1 que
     * chamar `balance()` num `map()` produziria — a tela lista todas as contas da clínica.
     *
     * @param  iterable<FinancialAccount>  $accounts
     * @return array<int, float> chave = account_id
     */
    public function balancesFor(iterable $accounts, ?\DateTimeInterface $at = null): array
    {
        $accounts = collect($accounts);
        $cutoff = $at === null ? Carbon::now() : Carbon::instance($at)->endOfDay();

        $movements = CashRegisterMovement::query()
            ->whereIn('account_id', $accounts->pluck('id'))
            ->where('occurred_at', '<=', $cutoff)
            ->with('paymentMethod')
            ->get()
            ->groupBy('account_id');

        return $accounts->mapWithKeys(function (FinancialAccount $account) use ($movements): array {
            $net = ($movements[$account->id] ?? collect())
                ->filter(fn (CashRegisterMovement $movement): bool => $account->opening_balance_date === null
                    || $movement->occurred_at >= $account->opening_balance_date->startOfDay())
                ->sum(fn (CashRegisterMovement $movement): float => $movement->signedAmount());

            return [$account->id => round((float) $account->opening_balance + $net, 2)];
        })->all();
    }

    /**
     * Extrato: as linhas que compõem o saldo, com saldo acumulado a cada movimento. É o que a
     * tela de conta mostra quando se clica no saldo — sem isso, "R$ 4.312,80" é um número sem
     * explicação.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statement(FinancialAccount $account, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $running = $this->balance($account, Carbon::instance($from)->subDay());

        return CashRegisterMovement::query()
            ->where('account_id', $account->id)
            ->whereBetween('occurred_at', [Carbon::instance($from)->startOfDay(), Carbon::instance($to)->endOfDay()])
            ->with('paymentMethod')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(function (CashRegisterMovement $movement) use (&$running): array {
                $running = round($running + $movement->signedAmount(), 2);

                return [
                    'id' => $movement->id,
                    'occurred_at' => $movement->occurred_at->toIso8601String(),
                    'type' => $movement->type->value,
                    'type_label' => $movement->type->label(),
                    'description' => $movement->description,
                    'payment_method' => $movement->paymentMethod?->name,
                    'amount' => $movement->signedAmount(),
                    'balance_after' => $running,
                ];
            })
            ->all();
    }
}
