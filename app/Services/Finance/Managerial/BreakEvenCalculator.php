<?php

namespace App\Services\Finance\Managerial;

use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;

/**
 * Ponto de equilíbrio (docs/gap-petflow/PLANO.md, 1.7) = despesas fixas ÷ MC%, com a MC% EXATA
 * (ΣMC ÷ Σreceita, sem arredondar antes da divisão) e arredondado para CIMA no centavo, pelo
 * mesmo motivo do preço mínimo (1.4): para baixo, o faturamento "de equilíbrio" ainda daria
 * prejuízo. Não há cadastro de despesa fixa × variável: a base são as despesas operacionais do
 * período (`basis: operating_expenses`). MC ≤ 0 ou sem receita: `amount` null com o motivo.
 */
final class BreakEvenCalculator
{
    public const BASIS = 'operating_expenses';

    public const REASON_NO_REVENUE = 'no_revenue';

    public const REASON_NON_POSITIVE_MARGIN = 'non_positive_margin';

    /** @return array{amount: string|null, basis: string, fixed_expenses: string, reason: string|null} */
    public function calculate(Money $fixedExpenses, Money $contributionMargin, Money $revenue): array
    {
        $result = ['amount' => null, 'basis' => self::BASIS, 'fixed_expenses' => $fixedExpenses->toString(), 'reason' => null];

        if (! $revenue->isPositive()) {
            return array_merge($result, ['reason' => self::REASON_NO_REVENUE]);
        }

        if (! $contributionMargin->isPositive()) {
            return array_merge($result, ['reason' => self::REASON_NON_POSITIVE_MARGIN]);
        }

        // despesas ÷ (MC ÷ receita) = despesas × receita ÷ MC — uma divisão só, exata até o arredondamento.
        $amount = $fixedExpenses->toBigDecimal()->multipliedBy($revenue->toBigDecimal())
            ->dividedBy($contributionMargin->toBigDecimal(), Money::SCALE, RoundingMode::Up);

        return array_merge($result, ['amount' => (string) $amount]);
    }

    /** Variante pela MC% já conhecida (ex. 24,77), para o exemplo do plano. */
    public function fromPercent(Money $fixedExpenses, string $marginPercent): ?string
    {
        $percent = Decimal::of($marginPercent);

        if (! $percent->isPositive()) {
            return null;
        }

        return (string) $fixedExpenses->toBigDecimal()->multipliedBy(100)->dividedBy($percent, Money::SCALE, RoundingMode::Up);
    }
}
