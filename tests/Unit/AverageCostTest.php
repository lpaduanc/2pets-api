<?php

namespace Tests\Unit;

use App\Services\Purchase\InstallmentPlanner;
use App\Services\Stock\AverageCostCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** Custo médio ponderado (doc 06) e parcelas da compra — números fixados. */
class AverageCostTest extends TestCase
{
    public function test_weighted_average_after_entry(): void
    {
        $calc = new AverageCostCalculator;

        // 10 un a 20,00 + 5 un a 26,00 = (200 + 130) / 15 = 22,00
        $this->assertSame(22.0, $calc->afterEntry(10, 20.0, 5, 26.0));
        // Estoque zerado: custo médio é o da entrada.
        $this->assertSame(26.0, $calc->afterEntry(0, 20.0, 5, 26.0));
        // Saldo negativo pondera como zero.
        $this->assertSame(30.0, $calc->afterEntry(-3, 20.0, 4, 30.0));
        // Quatro casas.
        $this->assertSame(10.3333, $calc->afterEntry(2, 10.0, 1, 11.0));
    }

    public function test_reversal_undoes_the_entry_when_units_are_still_in_stock(): void
    {
        $calc = new AverageCostCalculator;

        $this->assertSame(20.0, $calc->afterReversal(15, 22.0, 5, 26.0));
        // Tudo já vendido: mantém o médio vigente.
        $this->assertSame(22.0, $calc->afterReversal(5, 22.0, 5, 26.0));
        $this->assertSame(22.0, $calc->afterReversal(3, 22.0, 5, 26.0));
    }

    public function test_installments_split_cents_into_the_last_one_and_skip_weekends(): void
    {
        $plan = (new InstallmentPlanner)->plan(100.00, 3, CarbonImmutable::parse('2026-10-20'));

        $this->assertSame([33.33, 33.33, 33.34], array_column($plan, 'amount'));
        // 20/10 (ter), 20/11 (sex), 20/12 é domingo → 21/12 (seg).
        $this->assertSame(['2026-10-20', '2026-11-20', '2026-12-21'], array_column($plan, 'due_date'));
        $this->assertSame(100.0, round(array_sum(array_column($plan, 'amount')), 2));
    }

    public function test_installments_with_fixed_interval_in_days(): void
    {
        $plan = (new InstallmentPlanner)->plan(90.00, 2, CarbonImmutable::parse('2026-10-05'), 14);

        $this->assertSame(['2026-10-05', '2026-10-19'], array_column($plan, 'due_date'));
    }
}
