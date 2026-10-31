<?php

namespace Tests\Unit\Insights;

use App\Services\Crm\PurchaseRhythmCalculator;
use App\Services\Insights\InsightAbcClassifier;
use App\Services\Insights\ProfitabilityInsightMetrics;
use App\Support\Money\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Relatórios de performance (docs/gap-petflow/PLANO.md, 1.6) contra os casos de referência
 * compartilhados com o front (`tests/Fixtures/golden/performance.json`). Classes puras.
 */
class PerformanceGoldenTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function golden(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/performance.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function abcCases(): array
    {
        return collect(self::golden()['abc'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function marginCases(): array
    {
        return collect(self::golden()['margin'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function returnCases(): array
    {
        return collect(self::golden()['return_days'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('abcCases')]
    public function test_golden_abc(array $case): void
    {
        $classes = (new InsightAbcClassifier)->classify($case['input']);
        $actual = array_map(fn (string $bucket, array $class): array => ['bucket' => (int) $bucket] + $class, array_keys($classes), $classes);

        $this->assertSame($case['expected'], $actual);
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('marginCases')]
    public function test_golden_aggregated_margin_is_the_ratio_of_the_sums(array $case): void
    {
        $sum = fn (string $column): BigDecimal => array_reduce($case['input'], fn (BigDecimal $total, array $row): BigDecimal => $total->plus(Decimal::of($row[$column])), BigDecimal::zero());
        $percents = array_filter(array_map(fn (array $row): ?string => ProfitabilityInsightMetrics::percent(Decimal::of($row['contribution_margin']), Decimal::of($row['net_revenue'])), $case['input']));
        $average = array_reduce($percents, fn (BigDecimal $total, string $percent): BigDecimal => $total->plus($percent), BigDecimal::zero())
            ->dividedBy(count($percents), 2, RoundingMode::HalfUp);

        $this->assertSame($case['expected']['margin_percent'], ProfitabilityInsightMetrics::percent($sum('contribution_margin'), $sum('net_revenue')));
        $this->assertSame($case['expected']['simple_average'], (string) $average);
        $this->assertNotSame($case['expected']['margin_percent'], $case['expected']['simple_average']);
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('returnCases')]
    public function test_golden_average_return_days_and_the_sql_span_formula_agree(array $case): void
    {
        $calculator = new PurchaseRhythmCalculator;
        $days = array_map(fn (string $at): CarbonImmutable => CarbonImmutable::parse($at)->setTimezone(PurchaseRhythmCalculator::TIMEZONE)->startOfDay(), $case['input']);
        sort($days);

        $this->assertSame($case['expected'], $calculator->averageReturnDays($case['input']));
        $this->assertSame($case['expected'], $calculator->fromSpan(count($days), (int) $days[0]->diffInDays(end($days))));
    }
}
