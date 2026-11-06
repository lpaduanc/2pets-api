<?php

namespace Tests\Unit\Commercial\Goals;

use App\Services\Commercial\Goals\SalesGoalCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Conta da meta de venda contra `tests/Fixtures/golden/sales-goal.json` (PLANO 2.5). */
class SalesGoalCalculatorTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function goldenCases(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/golden/sales-goal.json'), true, flags: JSON_THROW_ON_ERROR);

        return collect($data['salesGoals'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('goldenCases')]
    public function test_golden_sales_goal(array $case): void
    {
        $in = $case['input'];

        $this->assertSame($case['expected'], (new SalesGoalCalculator)->progress($in['target'], $in['actual'], $in['total_days'], $in['elapsed_days'], $in['remaining_days']));
    }

    public function test_zero_goal_has_no_percentages(): void
    {
        $result = (new SalesGoalCalculator)->progress('0.00', '100.00', 20, 5, 15);

        $this->assertNull($result['achieved_percent']);
        $this->assertNull($result['projection_percent']);
        $this->assertSame('0.00', $result['needed_per_day']);
    }
}
