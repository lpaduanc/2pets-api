<?php

namespace Tests\Unit\Money;

use App\Models\Product;
use App\Models\SaleItem;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Quantidade exata (nunca arredondada) e devolução pelo rateio acumulado — docs/gap-petflow/
 * PLANO.md, 0.2 e 2.3. Sem banco.
 */
class QuantityAndRefundTest extends TestCase
{
    public function test_quantity_is_exact_and_refuses_more_than_four_places(): void
    {
        $this->assertSame('1.2500', Quantity::of('1.25')->toString());
        $this->assertSame('1.2500', Quantity::of(1.25)->toString());
        $this->assertSame(2, Quantity::of('1.2500')->decimalPlaces());
        $this->assertSame(0, Quantity::of('3.0000')->decimalPlaces());
        $this->assertSame('0.3000', Quantity::of('0.1')->plus('0.2')->toString());

        $this->expectException(InvalidArgumentException::class);
        Quantity::of('1.25005');
    }

    public function test_product_by_unit_refuses_fractions_and_bulk_product_accepts_them(): void
    {
        $unit = (new Product)->forceFill(['decimal_places_allowed' => 0]);
        $bulk = (new Product)->forceFill(['decimal_places_allowed' => 3]);

        $this->assertTrue($unit->acceptsQuantity('2'));
        $this->assertFalse($unit->acceptsQuantity('1.5'));
        $this->assertTrue($bulk->acceptsQuantity('1.250'));
        $this->assertFalse($bulk->acceptsQuantity('1.2505'));
        $this->assertSame(3, Product::defaultDecimalPlacesFor(' kg '));
        $this->assertSame(0, Product::defaultDecimalPlacesFor('CX'));
    }

    public function test_returning_every_unit_in_parts_refunds_exactly_the_line_total(): void
    {
        // Linha de R$ 10,00 com 3 un (3 × 10,00 − 20,00 de desconto): devolver 1 + 1 + 1 devolve
        // 3,33 + 3,34 + 3,33 = 10,00 — nunca 3,33 × 3 = 9,99 (cliente perde) nem 3,34 × 3 =
        // 10,02 (clínica perde).
        $item = (new SaleItem)->forceFill(['quantity' => '3', 'unit_price' => '10.00', 'discount' => '20.00']);

        $refunds = [];
        $returned = Quantity::zero();

        foreach (['1', '1', '1'] as $quantity) {
            $refunds[] = $item->refundFor($returned, Quantity::of($quantity))->toString();
            $returned = $returned->plus($quantity);
        }

        $this->assertSame(['3.33', '3.34', '3.33'], $refunds);
        $this->assertTrue(Money::sum($refunds)->isEqualTo('10.00'));
        $this->assertSame('3.33', $item->netUnitPrice());
    }

    public function test_bulk_return_refunds_the_proportion_of_the_weighed_line(): void
    {
        // Golden `balanca-01`: 1,250 kg × 32,90 = 41,13. Devolver 0,5 kg e depois 0,75 kg.
        $item = (new SaleItem)->forceFill(['quantity' => '1.250', 'unit_price' => '32.90', 'discount' => '0.00']);

        $first = $item->refundFor(Quantity::zero(), Quantity::of('0.5'));
        $rest = $item->refundFor(Quantity::of('0.5'), Quantity::of('0.75'));

        $this->assertSame('16.45', $first->toString()); // 41,13 × 0,4 = 16,452
        $this->assertSame('24.68', $rest->toString());
        $this->assertSame('41.13', $first->plus($rest)->toString());
    }
}
