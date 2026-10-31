<?php

namespace Tests\Unit\Commercial\Pricing;

use App\Enums\CardFeeReferenceMode;
use App\Models\Product;
use App\Models\Service;
use App\Services\Commercial\Pricing\PricingContext;
use App\Services\Commercial\Pricing\PricingQuoteCalculator;
use App\Services\Commercial\PricingService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fórmulas da precificação (docs/gap-petflow/PLANO.md, 1.4) contra os casos de referência
 * compartilhados com o front (`tests/Fixtures/golden/pricing.json`). Classe pura — sem banco.
 */
class PricingFormulasTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function goldenCases(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/golden/pricing.json'), true, flags: JSON_THROW_ON_ERROR);

        return collect($data['pricing'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('goldenCases')]
    public function test_golden_pricing(array $case): void
    {
        $input = $case['input'];
        $context = new PricingContext($input['card_fee_reference_percent'], $input['tax_percent'], $input['commission_percent']);

        $result = (new PricingQuoteCalculator(new PricingService))->calculate($input, $context, CardFeeReferenceMode::from($input['card_fee_reference_mode']));

        $this->assertSame($case['expected'], $result);
    }

    public function test_preco_01_formulas_one_by_one(): void
    {
        $pricing = new PricingService;

        $this->assertSame('50.0000', $pricing->markupPercent('40.00', '60.00'));
        $this->assertSame('33.3333', $pricing->marginPercent('40.00', '60.00'));
        $this->assertSame('60.00', $pricing->priceForMarkup('40.00', '50')->toString());
        $this->assertSame('60.00', $pricing->priceForMargin('40.00', '33.333333')->toString());
        $this->assertSame('47.62', $pricing->minimumViablePrice('40.00', new PricingContext('4.99', '6', '5'))?->toString());
    }

    public function test_minimum_price_rounds_up_even_a_hair_above_the_cent(): void
    {
        // 10,0001 / 1 = 10,0001 → 10,01 (HALF_UP daria 10,00 e o preço não cobriria o custo).
        $this->assertSame('10.01', (new PricingService)->minimumViablePrice('10.0001', new PricingContext)?->toString());
    }

    public function test_minimum_price_is_null_when_deductions_reach_one_hundred_percent(): void
    {
        $this->assertNull((new PricingService)->minimumViablePrice('10', new PricingContext('50', '30', '20')));
    }

    public function test_minimum_price_of_an_item_uses_its_cost_and_commission(): void
    {
        $product = new Product(['average_cost' => '40.0000', 'commission_percent' => '5']);
        $service = new Service(['price' => '50.00', 'direct_cost' => '40.00', 'commission_percent' => '5']);
        $service->setRelation('supplies', collect());
        $pricing = new PricingService;
        $context = new PricingContext('4.99', '6');

        $this->assertSame('47.62', $pricing->minimumViablePrice($product, $context)?->toString());
        $this->assertSame('47.62', $pricing->minimumViablePrice($service, $context)?->toString());
        // Comissão do contexto vence a do cadastro: 40 / (1 − 0,1099) = 44,938…
        $this->assertSame('44.94', $pricing->minimumViablePrice($product, new PricingContext('4.99', '6', '0'))?->toString());
    }

    public function test_percentages_are_null_when_the_base_is_zero(): void
    {
        $pricing = new PricingService;

        $this->assertNull($pricing->markupPercent('0', '30.00'));
        $this->assertNull($pricing->marginPercent('10.00', '0'));
    }

    public function test_margin_of_one_hundred_percent_or_more_has_no_price(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PricingService)->priceForMargin('10', '100');
    }
}
