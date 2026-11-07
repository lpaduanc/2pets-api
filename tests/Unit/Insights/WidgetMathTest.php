<?php

namespace Tests\Unit\Insights;

use App\Services\Insights\Widgets\WidgetMath;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** As contas dos widgets do painel (PLANO 3.6): variação, janela de dias e top N. */
class WidgetMathTest extends TestCase
{
    public function test_variation_is_relative_to_the_previous_value_and_null_without_it(): void
    {
        $this->assertSame('10.00', WidgetMath::variationPercent(Money::of('55.00'), Money::of('50.00')));
        $this->assertSame('-33.33', WidgetMath::variationPercent(Money::of('20.00'), Money::of('30.00')));
        // Anterior negativo (prejuízo): melhora de −100 para 50 é +150%, não −150%.
        $this->assertSame('150.00', WidgetMath::variationPercent(Money::of('50.00'), Money::of('-100.00')));
        $this->assertNull(WidgetMath::variationPercent(Money::of('10.00'), Money::zero()));
        $this->assertNull(WidgetMath::variationPercent(Money::of('10.00'), null));
    }

    public function test_window_is_inclusive_and_ends_today(): void
    {
        [$from, $to] = WidgetMath::window(CarbonImmutable::parse('2026-10-30 14:00'), 30);

        $this->assertSame('2026-10-01 00:00:00', $from->toDateTimeString());
        $this->assertSame('2026-10-30 23:59:59', $to->toDateTimeString());
        $this->assertSame('2026-10-30', WidgetMath::window(CarbonImmutable::parse('2026-10-30'), 1)[0]->toDateString());
    }

    public function test_top_orders_by_value_breaks_ties_by_key_and_cuts(): void
    {
        $rows = [
            ['name' => 'Luva', 'quantity' => '12.500'], ['name' => 'Algodão', 'quantity' => '40'], ['name' => 'Seringa', 'quantity' => '12.5'],
            ['name' => 'Gaze', 'quantity' => '3'], ['name' => 'Álcool', 'quantity' => '100.0001'], ['name' => 'Atadura', 'quantity' => '1'],
        ];

        $this->assertSame(['Álcool', 'Algodão', 'Luva', 'Seringa', 'Gaze'], array_column(WidgetMath::top($rows, 'quantity', 'name', 5), 'name'));
    }
}
