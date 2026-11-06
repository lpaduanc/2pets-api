<?php

namespace Tests\Unit\Finance\CostCenter;

use App\Services\Finance\CostCenter\CostCenterAllocator;
use App\Support\Money\Money;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rateio por centro de custo contra os casos de referência compartilhados com o front
 * (`tests/Fixtures/golden/cost-center.json`, PLANO 2.4). `Tests\TestCase` só porque a
 * `ValidationException` precisa do tradutor do container; a conta é pura.
 */
class CostCenterAllocatorTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function goldenCases(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/golden/cost-center.json'), true, flags: JSON_THROW_ON_ERROR);

        return collect($data['allocations'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('goldenCases')]
    public function test_golden_allocations(array $case): void
    {
        $allocator = new CostCenterAllocator;

        if (! $case['expected']['valid']) {
            $this->expectException(ValidationException::class);
        }

        $result = $allocator->allocate($case['input']['net_amount'], $case['input']['allocations']);

        $this->assertSame($case['expected']['amounts'], array_column($result, 'amount'));
        $this->assertSame($case['input']['net_amount'], Money::sum(array_column($result, 'amount'))->toString());
    }

    public function test_more_than_four_decimals_duplicates_and_non_positive_percents_are_refused(): void
    {
        $allocator = new CostCenterAllocator;

        foreach ([
            [['cost_center_id' => 1, 'percent' => '50.00001'], ['cost_center_id' => 2, 'percent' => '49.99999']],
            [['cost_center_id' => 1, 'percent' => '50'], ['cost_center_id' => 1, 'percent' => '50']],
            [['cost_center_id' => 1, 'percent' => '100'], ['cost_center_id' => 2, 'percent' => '0']],
            [],
        ] as $invalid) {
            try {
                $allocator->allocate('10.00', $invalid);
                $this->fail('Rateio inválido aceito: '.json_encode($invalid));
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('allocations', $e->errors());
            }
        }
    }
}
