<?php

namespace Tests\Unit\Money;

use App\Support\Money\Decimal;
use App\Support\Money\Money;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Money` contra os casos de referência compartilhados com o front
 * (`tests/Fixtures/golden/money.json`, plano docs/gap-petflow seção 0.3) e contra a
 * propriedade que justifica a classe: rateio sempre soma o total.
 */
class MoneyTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function golden(string $section): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/money.json'), true, flags: JSON_THROW_ON_ERROR);

        $cases = [];
        foreach ($data[$section] as $case) {
            $cases[$case['id']] = [$case];
        }

        return $cases;
    }

    public static function allocateCases(): array
    {
        return self::golden('allocate');
    }

    public static function allocateEquallyCases(): array
    {
        return self::golden('allocateEqually');
    }

    public static function installmentCases(): array
    {
        return self::golden('splitInInstallments');
    }

    public static function percentageCases(): array
    {
        return self::golden('percentage');
    }

    public static function timesCases(): array
    {
        return self::golden('times');
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('allocateCases')]
    public function test_allocate_matches_golden(array $case): void
    {
        $parts = Money::of($case['total'])->allocate($case['weights']);

        $this->assertSame($case['expected'], self::strings($parts));
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('allocateEquallyCases')]
    public function test_allocate_equally_matches_golden(array $case): void
    {
        $this->assertSame($case['expected'], self::strings(Money::of($case['total'])->allocateEqually($case['parts'])));
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('installmentCases')]
    public function test_split_in_installments_matches_golden(array $case): void
    {
        $parts = Money::of($case['total'])->splitInInstallments($case['count'], $case['remainderOnLast']);

        $this->assertSame($case['expected'], self::strings($parts));
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('percentageCases')]
    public function test_percentage_matches_golden(array $case): void
    {
        $this->assertSame($case['expected'], Money::of($case['amount'])->percentage($case['percent'])->toString());
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('timesCases')]
    public function test_times_matches_golden(array $case): void
    {
        $this->assertSame($case['expected'], Money::of($case['amount'])->times($case['factor'])->toString());
    }

    public function test_allocation_always_sums_to_the_total(): void
    {
        mt_srand(20261029);

        for ($run = 0; $run < 1000; $run++) {
            $total = Money::ofCents(mt_rand(-500000, 500000));
            $weights = array_map(
                fn (): string => sprintf('%d.%02d', mt_rand(0, 9999), mt_rand(0, 99)),
                range(1, mt_rand(1, 12)),
            );

            $this->assertTrue(
                Money::sum($total->allocate($weights))->isEqualTo($total),
                "Rateio de {$total} por [".implode(', ', $weights).'] não somou o total.',
            );
        }
    }

    public function test_allocation_preserves_keys(): void
    {
        $parts = Money::of('10.00')->allocate([42 => 1, 7 => 1]);

        $this->assertSame([42, 7], array_keys($parts));
        $this->assertSame(['5.00', '5.00'], self::strings($parts));
    }

    public function test_allocation_rejects_negative_or_missing_weights(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('10.00')->allocate(['1', '-1']);
    }

    public function test_allocation_rejects_empty_weights(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('10.00')->allocate([]);
    }

    public function test_float_input_is_read_without_binary_noise(): void
    {
        // 2.675 em float é 2.67499999…; `round()` do PHP compensa, mas a conversão ingênua não.
        $this->assertSame('2.68', Money::of(2.675)->toString());
        $this->assertSame('0.30', Money::of(0.1 + 0.2)->toString());
        $this->assertSame('0.00', Money::of(1.0E-5)->toString());
    }

    public function test_minimum_price_rounds_up(): void
    {
        // Plano, seção 1.4 (golden `preco-01`): 40 / 0,8401 = 47,6134… → 47,62, nunca 47,61.
        $exact = Decimal::of('40')->dividedBy('0.8401', 10, RoundingMode::HalfUp);

        $this->assertSame('47.62', Money::of($exact, RoundingMode::Up)->toString());
        $this->assertSame('47.61', Money::of($exact)->toString());
    }

    public function test_arithmetic_and_comparisons(): void
    {
        $value = Money::of('10.00');

        $this->assertSame('12.50', $value->plus('2.5')->toString());
        $this->assertSame('-2.50', $value->minus('12.50')->toString());
        $this->assertSame('2.50', $value->minus('12.50')->abs()->toString());
        $this->assertSame('10.00', $value->max('9.99')->toString());
        $this->assertSame('9.99', $value->min('9.99')->toString());
        $this->assertTrue($value->isGreaterThan('9.99'));
        $this->assertTrue(Money::zero()->isZero());
        $this->assertSame('1000', (string) $value->cents());
        $this->assertSame('"10.00"', json_encode($value));
    }

    /**
     * @param  array<array-key, Money>  $parts
     * @return list<string>
     */
    private static function strings(array $parts): array
    {
        return array_values(array_map(fn (Money $part): string => $part->toString(), $parts));
    }
}
