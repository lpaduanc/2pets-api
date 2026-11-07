<?php

namespace App\Services\Insights\Widgets;

use App\Models\User;
use App\Services\Finance\Managerial\ManagerialIncomeStatementService;
use Carbon\CarbonImmutable;

/**
 * A DRE gerencial (1.7) do mês corrente e a do mês anterior, para os widgets de lucro (3.6) —
 * sempre pelo mesmo service da DRE, nunca uma conta à parte. Mês corrente = do dia 1 ao fim do
 * mês de `$today` (o que ainda não aconteceu simplesmente não tem lançamento); anterior = o mês
 * cheio. Guardado por requisição: os três widgets de lucro pedem o mesmo par.
 */
final class ManagerialMonthComparison
{
    /** @var array<string, array{current: array<string, mixed>, previous: array<string, mixed>}> */
    private array $cache = [];

    public function __construct(private readonly ManagerialIncomeStatementService $statement) {}

    /** @return array{current: array<string, mixed>, previous: array<string, mixed>} */
    public function for(User $user, CarbonImmutable $today, bool $breakdown = false): array
    {
        $key = $user->id.'|'.$today->format('Y-m').'|'.(int) $breakdown;
        $start = $today->startOfMonth();
        $previous = $start->subMonthNoOverflow();

        return $this->cache[$key] ??= [
            'current' => $this->statement->generate($user, $start, $start->endOfMonth(), null, $breakdown),
            'previous' => $this->statement->generate($user, $previous, $previous->endOfMonth(), null, $breakdown),
        ];
    }

    /**
     * Margem da própria linha: linha ÷ receita líquida (linha `net_revenue` da DRE) × 100, 2 casas
     * HALF_UP; pode ser negativa; `null` sem receita.
     *
     * @param  array<string, mixed>  $statement
     */
    public static function marginOf(array $statement, string $key): ?string
    {
        $revenue = \App\Support\Money\Money::of(self::line($statement, 'net_revenue'));

        if ($revenue->isZero()) {
            return null;
        }

        return (string) \App\Support\Money\Money::of(self::line($statement, $key))->toBigDecimal()->multipliedBy(100)
            ->dividedBy($revenue->toBigDecimal(), 2, \Brick\Math\RoundingMode::HalfUp);
    }

    /** @param  array<string, mixed>  $statement */
    public static function line(array $statement, string $key): string
    {
        return (string) collect($statement['lines'])->firstWhere('key', $key)['total'];
    }
}
