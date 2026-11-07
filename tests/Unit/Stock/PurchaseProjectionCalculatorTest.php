<?php

namespace Tests\Unit\Stock;

use App\Services\Stock\Projection\PurchaseProjectionCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Projeção de compra (PLANO 3.4) contra `tests/Fixtures/golden/purchase-projection.json`. */
class PurchaseProjectionCalculatorTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function goldenCases(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/purchase-projection.json'), true, flags: JSON_THROW_ON_ERROR);

        return collect($data['projections'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case, $data['today']]])->all();
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('goldenCases')]
    public function test_golden_projection(array $case, string $today): void
    {
        $in = $case['input'];

        $this->assertSame($case['expected'], (new PurchaseProjectionCalculator)->project(
            $in['daily_demand'], $in['stock'], $in['open_orders'], $in['lead_time_days'], $in['coverage_days'], $in['multiple'], $in['use_safety_stock'], CarbonImmutable::parse($today),
        ));
    }
}
