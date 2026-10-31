<?php

namespace Tests\Unit\Commercial\Profitability;

use App\Services\Commercial\Profitability\ProfitabilityCalculator;
use App\Services\Commercial\Profitability\SaleAllocationService;
use App\Support\Money\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Rentabilidade por item (docs/gap-petflow/PLANO.md, 1.5) contra os casos de referência
 * compartilhados com o front (`tests/Fixtures/golden/profitability.json`). Classe pura.
 */
class ProfitabilityCalculatorTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function goldenCases(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/golden/profitability.json'), true, flags: JSON_THROW_ON_ERROR);

        return collect($data['profitability'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('goldenCases')]
    public function test_golden_profitability(array $case): void
    {
        $this->assertSame($case['expected'], (new ProfitabilityCalculator(new SaleAllocationService))->calculate($case['input']));
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('goldenCases')]
    public function test_parts_add_up_to_the_sale_to_the_cent(array $case): void
    {
        $result = (new ProfitabilityCalculator(new SaleAllocationService))->calculate($case['input']);

        foreach (['net_revenue', 'cost_amount', 'card_fee_share', 'tax_amount', 'commission_amount', 'contribution_margin'] as $column) {
            $this->assertSame($result['totals'][$column], Money::sum(array_column($result['items'], $column))->toString(), $column);
        }
    }

    public function test_sale_margin_is_the_ratio_of_the_sums_not_the_average_of_percentages(): void
    {
        $data = self::goldenCases()['rentab-01'][0];
        $result = (new ProfitabilityCalculator(new SaleAllocationService))->calculate($data['input']);

        $this->assertSame('24.77', $result['totals']['margin_percent']);
        $this->assertNotSame('33.47', $result['totals']['margin_percent']);
    }

    public function test_sale_with_zero_revenue_has_no_margin_percent(): void
    {
        $result = (new ProfitabilityCalculator(new SaleAllocationService))->calculate([
            'header_discount' => '0', 'card_fee' => '0', 'tax_percent' => null,
            'items' => [['sale_item_id' => 1, 'description' => 'Brinde', 'quantity' => '1', 'returned_quantity' => '0', 'courtesy_kind' => 'gift', 'total' => '0', 'unit_cost' => '21', 'commission' => '0']],
        ]);

        $this->assertSame(['-21.00', null], [$result['totals']['contribution_margin'], $result['totals']['margin_percent']]);
    }
}
