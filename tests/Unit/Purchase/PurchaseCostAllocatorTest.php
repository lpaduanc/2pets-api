<?php

namespace Tests\Unit\Purchase;

use App\Services\Purchase\PurchaseCostAllocator;
use App\Support\Money\Money;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Custo de aquisição real contra os casos de referência compartilhados com o front
 * (`tests/Fixtures/golden/purchase-cost.json`, plano docs/gap-petflow 0.3 e 1.2) e contra as
 * propriedades que justificam a classe: o rateio soma o total do cabeçalho, e o total da compra
 * é a soma dos custos reais dos itens. `Tests\TestCase` porque a recusa é `ValidationException`
 * (precisa do container), mas nada aqui toca o banco.
 */
class PurchaseCostAllocatorTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function goldenCases(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/purchase-cost.json'), true, flags: JSON_THROW_ON_ERROR);

        return collect($data['landedCost'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('goldenCases')]
    public function test_golden_landed_cost(array $case): void
    {
        $result = (new PurchaseCostAllocator)->allocate($case['header'], $case['items']);

        foreach ($case['expected']['items'] as $index => $expected) {
            $this->assertSame(self::sorted($expected), self::sorted(array_intersect_key($result['items'][$index], $expected)), "item {$index}");
        }

        $this->assertSame(self::sorted($case['expected']['totals']), self::sorted(array_intersect_key($result['totals'], $case['expected']['totals'])));
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private static function sorted(array $values): array
    {
        ksort($values);

        return $values;
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('goldenCases')]
    public function test_purchase_total_equals_the_sum_of_landed_costs(array $case): void
    {
        $result = (new PurchaseCostAllocator)->allocate($case['header'], $case['items']);

        $this->assertSame($result['totals']['total'], Money::sum(array_column($result['items'], 'landed_total_cost'))->toString());
    }

    public function test_explicit_item_shares_are_kept_and_must_match_the_header(): void
    {
        $items = [
            ['quantity' => '1', 'unit_cost' => '100.00', 'freight_share' => '1.00'],
            ['quantity' => '1', 'unit_cost' => '100.00', 'freight_share' => '9.00'],
        ];

        $result = (new PurchaseCostAllocator)->allocate(['total_freight' => '10.00'], $items);
        $this->assertSame(['1.00', '9.00'], array_column($result['items'], 'freight_share'));

        $this->expectException(ValidationException::class);
        (new PurchaseCostAllocator)->allocate(['total_freight' => '12.00'], $items);
    }

    public function test_header_discount_is_allocated_when_items_have_none_and_refused_when_it_double_counts(): void
    {
        $items = [['quantity' => '1', 'unit_cost' => '200.00'], ['quantity' => '1', 'unit_cost' => '60.00']];

        $result = (new PurchaseCostAllocator)->allocate(['total_discount' => '13.00'], $items);
        // Mesmo rateio do golden `rentab-01-desconto-cabecalho` (money.json).
        $this->assertSame(['10.00', '3.00'], array_column($result['items'], 'discount'));
        $this->assertSame('247.00', $result['totals']['total']);

        $items[0]['discount'] = '5.00';
        $this->expectException(ValidationException::class);
        (new PurchaseCostAllocator)->allocate(['total_discount' => '13.00'], $items);
    }

    public function test_stock_quantity_beyond_four_decimals_is_refused_instead_of_rounded(): void
    {
        $this->expectException(ValidationException::class);

        (new PurchaseCostAllocator)->allocate([], [['quantity' => '0.3333', 'unit_cost' => '1.00', 'conversion_factor' => '1.5']]);
    }
}
