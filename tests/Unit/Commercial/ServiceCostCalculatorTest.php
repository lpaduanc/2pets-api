<?php

namespace Tests\Unit\Commercial;

use App\Services\Commercial\ServiceCost\ServiceCostCalculator;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Custo do serviço pela ficha técnica contra os casos de referência compartilhados com o front
 * (`tests/Fixtures/golden/service-cost.json`, plano docs/gap-petflow 0.3 e 1.3) e contra a
 * propriedade que a tela depende: o custo do serviço é exatamente a soma das linhas listadas
 * mais o custo direto. Classe pura — nada aqui toca o banco nem o container.
 */
class ServiceCostCalculatorTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function goldenCases(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/service-cost.json'), true, flags: JSON_THROW_ON_ERROR);

        return collect($data['serviceCost'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('goldenCases')]
    public function test_golden_service_cost(array $case): void
    {
        $input = $case['input'];

        $result = (new ServiceCostCalculator)->calculate($input['price'], $input['direct_cost'], $input['items']);

        $this->assertSame($case['expected'], $result);
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('goldenCases')]
    public function test_unit_cost_is_the_sum_of_the_listed_lines_plus_direct_cost(array $case): void
    {
        $input = $case['input'];

        $result = (new ServiceCostCalculator)->calculate($input['price'], $input['direct_cost'], $input['items']);

        $sum = array_reduce($result['items'], fn (BigDecimal $total, array $item): BigDecimal => $total->plus($item['line_cost']), BigDecimal::zero());
        $this->assertTrue($sum->plus($result['direct_cost'])->isEqualTo($result['unit_cost']));
        $this->assertTrue($sum->isEqualTo($result['supplies_cost']));
    }

    public function test_float_input_from_a_validated_request_gives_the_same_exact_result(): void
    {
        $result = (new ServiceCostCalculator)->calculate(50.0, 0, [
            ['product_id' => 1, 'quantity' => 30, 'average_cost' => 0.08],
            ['product_id' => 2, 'quantity' => 20.0, 'average_cost' => 0.1],
            ['product_id' => 3, 'quantity' => 2, 'average_cost' => '1.25'],
        ]);

        $this->assertSame('6.9000', $result['unit_cost']);
        $this->assertSame('43.10', $result['margin_amount']);
    }

    public function test_cost_above_price_gives_negative_margin_without_rounding_it_away(): void
    {
        $result = (new ServiceCostCalculator)->calculate('2.00', '0.00', [
            ['product_id' => 1, 'quantity' => '0.001', 'average_cost' => '2.01'],
            ['product_id' => 2, 'quantity' => '1', 'average_cost' => '2.00'],
        ]);

        // 0,001 × 2,01 = 0,00201 → 0,0020; custo 2,0020; margem −0,0020 → −0,00 em R$, mas % fiel.
        $this->assertSame('2.0020', $result['unit_cost']);
        $this->assertSame('0.00', $result['margin_amount']);
        $this->assertSame('-0.1000', $result['margin_percent']);
        $this->assertSame('-0.0999', $result['markup_percent']);
    }

    public function test_product_without_average_cost_costs_zero(): void
    {
        $result = (new ServiceCostCalculator)->calculate('10.00', null, [
            ['product_id' => 9, 'quantity' => '5', 'average_cost' => null],
        ]);

        $this->assertSame([['product_id' => 9, 'unit_cost' => '0.0000', 'line_cost' => '0.0000']], $result['items']);
        $this->assertSame('0.00', $result['direct_cost']);
        $this->assertNull($result['markup_percent']);
    }
}
