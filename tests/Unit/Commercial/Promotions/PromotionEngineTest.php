<?php

namespace Tests\Unit\Commercial\Promotions;

use App\Services\Commercial\Promotions\PromotionEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Motor de promoções contra `tests/Fixtures/golden/promotion.json` (PLANO 3.2). */
class PromotionEngineTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>}> */
    public static function goldenCases(): array
    {
        $data = json_decode((string) file_get_contents(__DIR__.'/../../../Fixtures/golden/promotion.json'), true, flags: JSON_THROW_ON_ERROR);

        return collect($data['promotions'])->mapWithKeys(fn (array $case): array => [$case['id'] => [$case]])->all();
    }

    /** @param  array<string, mixed>  $case */
    #[DataProvider('goldenCases')]
    public function test_golden_promotion(array $case): void
    {
        $result = (new PromotionEngine)->evaluate($case['input']['lines'], $case['input']['promotions']);

        foreach ($case['expected']['lines'] as $lineId => $expected) {
            $this->assertSame($expected['promotion_id'], $result['lines'][(int) $lineId]['promotion_id'], "linha {$lineId}");
            $this->assertSame($expected['promotion_discount'], $result['lines'][(int) $lineId]['promotion_discount'], "linha {$lineId}");
        }

        $this->assertSame($case['expected']['total_promotion_discount'], $result['total_promotion_discount']);
    }

    public function test_fractional_quantity_only_takes_percent_promotions_and_discount_never_exceeds_the_line(): void
    {
        $lines = [['id' => 1, 'sellable_type' => 'product', 'sellable_id' => 1, 'group_id' => 3, 'brand_id' => null, 'unit_price' => '32.90', 'quantity' => '1.250']];
        $engine = new PromotionEngine;

        $this->assertSame('0.00', $engine->evaluate($lines, [['id' => 1, 'name' => 'L3P2', 'type' => 'buy_x_pay_y', 'priority' => 0, 'stackable' => false, 'config' => ['buy' => 1, 'pay' => 0, 'targets' => [['type' => 'group', 'id' => 3]]]]])['total_promotion_discount']);
        // 1,250 × 32,90 = 41,125 → 41,13; 10% → 4,11.
        $this->assertSame('4.11', $engine->evaluate($lines, [['id' => 2, 'name' => 'Granel 10%', 'type' => 'category_discount', 'priority' => 0, 'stackable' => false, 'config' => ['group_id' => 3, 'percent' => '10']]])['total_promotion_discount']);

        $two = [
            ['id' => 3, 'name' => 'Tudo 100%', 'type' => 'category_discount', 'priority' => 2, 'stackable' => false, 'config' => ['group_id' => 3, 'percent' => '100']],
            ['id' => 4, 'name' => 'Mais 50%', 'type' => 'category_discount', 'priority' => 1, 'stackable' => true, 'config' => ['group_id' => 3, 'percent' => '50']],
        ];
        $this->assertSame('41.13', $engine->evaluate($lines, $two)['total_promotion_discount']);
    }
}
