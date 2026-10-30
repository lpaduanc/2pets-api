<?php

namespace Tests\Unit;

use App\Services\Purchase\InstallmentPlanner;
use App\Services\Stock\AverageCostCalculator;
use App\Services\Stock\StockValuation;
use App\Support\Money\Quantity;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Custo médio ponderado (doc 06) sobre o valor exato do estoque (docs/gap-petflow/PLANO.md,
 * 0.2) e parcelas da compra — números fixados. Todo valor em centavos, e a invariante:
 * entrou + ajuste a crédito = saiu + ajuste a débito + ficou, exato.
 */
class AverageCostTest extends TestCase
{
    private AverageCostCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new AverageCostCalculator;
    }

    public function test_weighted_average_after_entry(): void
    {
        // 10 un a 20,00 + 5 un a 26,00 = (200 + 130) / 15 = 22,00
        [$after] = $this->calc->afterEntry(self::position('10', '200', '20'), Quantity::of(5), '130');
        $this->assertSame('22.0000', (string) $after->averageCost);
        $this->assertSame('330.00', (string) $after->value);

        // Estoque zerado: custo médio é o da entrada.
        [$fromZero] = $this->calc->afterEntry(self::position('0', '0', '20'), Quantity::of(5), '130');
        $this->assertSame('26.0000', (string) $fromZero->averageCost);

        // Dízima: 2 un a 10 + 1 a 11 = 31/3 → médio exibido 10,3333 (4 casas, HALF_UP); o valor (31,00) segue exato.
        [$repeating] = $this->calc->afterEntry(self::position('2', '20', '10'), Quantity::of(1), '11');
        $this->assertSame('10.3333', (string) $repeating->averageCost);
    }

    public function test_fractional_entry_keeps_the_exact_value_of_the_invoice_line(): void
    {
        // 12,5 kg por R$ 411,25 (linha da nota): custo 32,9000/kg e valor 411,25 exatos.
        [$after] = $this->calc->afterEntry(self::position('0', '0', '0'), Quantity::of('12.5'), '411.25');

        $this->assertSame('32.9000', (string) $after->averageCost);
        $this->assertSame('411.25', (string) $after->value);
    }

    public function test_entry_covering_units_sold_without_stock_books_the_cost_difference(): void
    {
        // Compra 1 por 10 → venda 3 (2 sem estoque, lançadas a 10) → compra 5 por 100 (20/un).
        // As 2 devidas custaram 40, não 20: ajuste de −20 (custo a mais); ficam 3 a 20.
        [$position] = $this->calc->afterEntry(self::position('0', '0', '0'), Quantity::of(1), '10');
        [$position, $sold] = $this->calc->afterExit($position, Quantity::of(3));
        [$position, $variance] = $this->calc->afterEntry($position, Quantity::of(5), '100');

        $this->assertSame('30.00', (string) $sold);
        $this->assertSame('-20.00', (string) $variance);
        $this->assertSame('60.00', (string) $position->value);
        $this->assertSame('20.0000', (string) $position->averageCost);
    }

    public function test_reversal_undoes_the_entry_when_units_are_still_in_stock(): void
    {
        [$after, $variance] = $this->calc->afterReversal(self::position('15', '330', '22'), Quantity::of(5), '130');

        $this->assertSame('20.0000', (string) $after->averageCost);
        $this->assertTrue($variance->isZero());
    }

    public function test_reversal_after_the_units_were_sold_books_the_difference(): void
    {
        // A 5 por 50 + B 5 por 100 (médio 15) → venda 5 (75) → cancela B (sai 100). Só havia 75:
        // ajuste de +25, saldo zero, valor zero.
        [$after, $variance] = $this->calc->afterReversal(self::position('5', '75', '15'), Quantity::of(5), '100');

        $this->assertTrue($after->value->isZero());
        $this->assertSame('25.00', (string) $variance);
        $this->assertSame('15.0000', (string) $after->averageCost);
    }

    public function test_revaluation_books_the_value_difference(): void
    {
        [$after, $variance] = $this->calc->revalued(self::position('3', '10', '3.33'), '5');

        $this->assertSame('15.00', (string) $after->value);
        $this->assertSame('5.00', (string) $variance);
    }

    public function test_exit_of_everything_takes_the_whole_value_without_residue(): void
    {
        // 3 un por R$ 10,00: médio 3,33. Sair 1 + 1 + 1 zera o valor exato.
        [$position] = $this->calc->afterEntry(self::position('0', '0', '0'), Quantity::of(3), '10');
        $left = BigDecimal::zero();

        foreach ([1, 1, 1] as $quantity) {
            [$position, $exitValue] = $this->calc->afterExit($position, Quantity::of($quantity));
            $left = $left->plus($exitValue);
        }

        $this->assertTrue($position->value->isZero());
        $this->assertSame('10.00', (string) $left->toScale(StockValuation::VALUE_SCALE));
    }

    public function test_value_is_conserved_across_any_sequence_of_movements(): void
    {
        mt_srand(20261105);

        for ($run = 0; $run < 300; $run++) {
            $this->assertConservedOver(30, $run);
        }
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

    /**
     * Entradas, saídas (inclusive além do saldo), reversões e reavaliações aleatórias: ao fim,
     * entrou + crédito = saiu + débito + ficou, sem um centésimo de diferença.
     */
    private function assertConservedOver(int $steps, int $run): void
    {
        $position = self::position('0', '0', '0');
        $in = BigDecimal::zero();
        $out = BigDecimal::zero();
        $entries = [];

        for ($step = 0; $step < $steps; $step++) {
            $quantity = Quantity::of(sprintf('%d.%03d', mt_rand(0, 20), mt_rand(1, 999)));
            $variance = BigDecimal::zero();

            switch (mt_rand(0, 3)) {
                case 0:
                    $value = sprintf('%d.%02d', mt_rand(0, 900), mt_rand(0, 99));
                    [$position, $variance] = $this->calc->afterEntry($position, $quantity, $value);
                    $in = $in->plus($value);
                    $entries[] = [$quantity, $value];
                    break;
                case 1:
                    [$position, $exitValue] = $this->calc->afterExit($position, $quantity);
                    $out = $out->plus($exitValue);
                    break;
                case 2:
                    if ($entries === []) {
                        continue 2;
                    }
                    [$entryQuantity, $entryValue] = array_pop($entries);
                    [$position, $variance] = $this->calc->afterReversal($position, $entryQuantity, $entryValue);
                    $out = $out->plus($entryValue);
                    break;
                default:
                    [$position, $variance] = $this->calc->revalued($position, sprintf('%d.%02d', mt_rand(0, 50), mt_rand(0, 99)));
            }

            $in = $in->plus($variance);
        }

        $this->assertTrue(
            $in->isEqualTo($out->plus($position->value)),
            "Rodada {$run}: entrou {$in}, saiu {$out}, ficou {$position->value}."
        );
    }

    private static function position(string $quantity, string $value, string $average): StockValuation
    {
        return new StockValuation(
            Quantity::of($quantity),
            BigDecimal::of($value)->toScale(StockValuation::VALUE_SCALE),
            BigDecimal::of($average)->toScale(StockValuation::COST_SCALE),
        );
    }
}
