<?php

namespace Tests\Unit\Purchase;

use App\Models\Product;
use App\Services\Purchase\LandedPriceSuggester;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Preço sugerido sobre o custo real por unidade de estoque — golden `suggestedPrice` em
 * `tests/Fixtures/golden/purchase-cost.json` (plano docs/gap-petflow 1.2). Sem banco.
 */
class LandedPriceSuggesterTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function goldenCases(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/golden/purchase-cost.json'), true, flags: JSON_THROW_ON_ERROR);

        return collect($data['suggestedPrice'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('goldenCases')]
    public function test_golden_suggested_price(array $case): void
    {
        $price = app(LandedPriceSuggester::class)->suggestedPrice(null, ['markup_percent' => $case['markup_percent']], $case['landed_unit_cost']);

        $this->assertSame($case['expected'], $price);
    }

    public function test_falls_back_to_the_product_markup_when_the_item_has_none(): void
    {
        $product = new Product(['markup_percent' => 100, 'price' => 99]);

        $this->assertSame('42.00', app(LandedPriceSuggester::class)->suggestedPrice($product, [], '21.0000'));
    }
}
