<?php

namespace App\Services\Finance\ClientCredit;

use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * As contas do crediário parcelado (docs/gap-petflow/PLANO.md, 2.2), puras — sem banco.
 *
 * Parcelas: o saldo dividido em n pelo maior resto (`Money::allocateEqually`: 100,00 em 3 =
 * 33,34 / 33,33 / 33,33; a sobra vai para as primeiras), vencimento mensal a partir do 1º no MESMO
 * dia — dia que o mês não tem vira o último dia dele (31/01 → 28 ou 29/02, 31/03 → 30/04).
 *
 * Encargos de atraso, calculados na leitura (nunca gravados por job), sobre o saldo da parcela:
 *   multa = round(saldo × multa%)                      uma vez, e só se dias de atraso > carência
 *   juros = round(saldo × juros_am/100 × dias/30)      pro rata die desde o vencimento
 * A carência (tolerância) só dispensa a MULTA: os juros de mora remuneram o atraso desde o 1º dia
 * (CDC art. 52 §1º limita a multa a 2%; os juros correm do vencimento). Depois de um pagamento que
 * quita os encargos da parcela, a multa não volta e os juros recomeçam daquela data; encargo pago
 * em parte é descontado do próximo cálculo.
 *
 * Pagamento FIFO: da parcela mais antiga para a mais nova, primeiro encargos (já com o desconto
 * concedido), depois o principal. Casos de referência em `tests/Fixtures/golden/client-credit.json`.
 */
final class ClientCreditCalculator
{
    private const DAYS_PER_MONTH = 30;

    /** @return list<array{number: int, due_date: string, amount: string}> */
    public function schedule(string $total, int $count, string $firstDueDate): array
    {
        $first = CarbonImmutable::parse($firstDueDate);

        return array_map(fn (Money $amount, int $index): array => [
            'number' => $index + 1,
            'due_date' => self::monthlyDueDate($first, $index)->toDateString(),
            'amount' => $amount->toString(),
        ], Money::of($total)->allocateEqually($count), array_keys(array_fill(0, $count, null)));
    }

    /**
     * @param  array{amount: string, paid_principal: string, due_date: string, fees_settled_on?: string|null, open_fees_paid?: string}  $installment
     * @param  array{late_fee_percent: string, monthly_interest_percent: string, grace_days: int}  $policy
     * @return array{remaining_principal: string, days_late: int, late_fee: string, interest: string, fees: string, total_due: string}
     */
    public function dueOn(array $installment, array $policy, string $date): array
    {
        $remaining = Money::of($installment['amount'])->minus($installment['paid_principal']);
        $on = CarbonImmutable::parse($date)->startOfDay();
        $due = CarbonImmutable::parse($installment['due_date'])->startOfDay();
        $daysLate = max(0, (int) $due->diffInDays($on, false));
        $settledOn = isset($installment['fees_settled_on']) ? CarbonImmutable::parse($installment['fees_settled_on'])->startOfDay() : null;
        $interestDays = max(0, (int) ($settledOn !== null && $settledOn->gt($due) ? $settledOn : $due)->diffInDays($on, false));

        $lateFee = $remaining->isPositive() && $settledOn === null && $daysLate > (int) $policy['grace_days']
            ? $remaining->percentage(Decimal::of($policy['late_fee_percent']))
            : Money::zero();
        $interest = $remaining->isPositive() ? Money::of($remaining->toBigDecimal()->multipliedBy(Decimal::of($policy['monthly_interest_percent']))->multipliedBy($interestDays)
            ->dividedBy(100 * self::DAYS_PER_MONTH, 10, RoundingMode::HalfUp)) : Money::zero();
        $fees = $lateFee->plus($interest)->minus($installment['open_fees_paid'] ?? '0')->max(Money::zero());

        return [
            'remaining_principal' => $remaining->toString(),
            'days_late' => $daysLate,
            'late_fee' => $lateFee->toString(),
            'interest' => $interest->toString(),
            'fees' => $fees->toString(),
            'total_due' => $remaining->plus($fees)->toString(),
        ];
    }

    /**
     * FIFO: encargos (menos o desconto) e depois principal, da parcela mais antiga para a mais nova.
     *
     * @param  list<array<string, mixed>>  $installments  já em ordem (vencimento, id), com `id`
     * @param  array{late_fee_percent: string, monthly_interest_percent: string, grace_days: int}  $policy
     * @return array{items: list<array{installment_id: int, fees: string, fee_discount: string, principal: string, settles_fees: bool}>, principal_total: string, fees_total: string, fee_discount_total: string, unapplied: string}
     */
    public function allocate(array $installments, array $policy, string $date, string $payment, string $feeDiscount = '0'): array
    {
        $cash = Money::of($payment);
        $discount = Money::of($feeDiscount);
        $items = [];

        foreach ($installments as $installment) {
            if (! $cash->isPositive() && ! $discount->isPositive()) {
                break;
            }

            // Cada plano tem a política congelada no dia em que nasceu.
            $due = $this->dueOn($installment, $installment['policy'] ?? $policy, $date);
            $waived = Money::of($due['fees'])->min($discount);
            $fees = Money::of($due['fees'])->minus($waived)->min($cash);
            $principal = $cash->minus($fees)->min($due['remaining_principal']);
            $discount = $discount->minus($waived);
            $cash = $cash->minus($fees)->minus($principal);

            if ($fees->isPositive() || $waived->isPositive() || $principal->isPositive()) {
                $items[] = [
                    'installment_id' => (int) $installment['id'],
                    'fees' => $fees->toString(),
                    'fee_discount' => $waived->toString(),
                    'principal' => $principal->toString(),
                    // Só "quita os encargos" quando havia encargo: pagamento antecipado não pode
                    // marcar a parcela como já multada.
                    'settles_fees' => Money::of($due['fees'])->isPositive() && $fees->plus($waived)->isEqualTo($due['fees']),
                ];
            }
        }

        return [
            'items' => $items,
            'principal_total' => Money::sum(array_column($items, 'principal'))->toString(),
            'fees_total' => Money::sum(array_column($items, 'fees'))->toString(),
            'fee_discount_total' => Money::sum(array_column($items, 'fee_discount'))->toString(),
            'unapplied' => $cash->toString(),
        ];
    }

    private static function monthlyDueDate(CarbonImmutable $first, int $index): CarbonImmutable
    {
        $month = $first->startOfMonth()->addMonthsNoOverflow($index);

        return $month->setDay(min($first->day, $month->daysInMonth));
    }
}
