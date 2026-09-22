<?php

namespace Tests\Feature\Commercial;

use App\Models\PaymentMethod;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCommissionFixtures;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Critérios de aceite do docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md
 * para as 4 bases de cálculo, verificados via `GET commissions/open`.
 */
class CommissionBaseCalculationTest extends TestCase
{
    use BuildsCommissionFixtures;
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_net_of_discount_base_applies_the_percent_after_the_item_discount(): void
    {
        $this->makeCommissionRule(['calculation_base' => 'net_of_discount', 'percent' => 10]);
        $product = $this->makeProduct(['price' => 100]);

        $this->paySaleWithDiscount($product, discount: 10);

        $open = $this->openCommissionsFor($this->groomerMember->id);

        // `assertJsonPath` usa `assertSame`: valor inteiro serializa sem casa decimal em JSON
        // (`90`, não `90.0`), então o esperado aqui é `int`.
        $open->assertJsonPath('data.0.base_amount', 90)
            ->assertJsonPath('data.0.commission_amount', 9);
    }

    public function test_net_of_card_fee_base_deducts_the_proportional_acquirer_fee(): void
    {
        $this->makeCommissionRule(['calculation_base' => 'net_of_card_fee', 'percent' => 10]);
        $card = PaymentMethod::create([
            'organization_id' => $this->clinic->id, 'name' => 'Cartão Teste', 'kind' => 'credit_card',
            'direction' => 'in', 'fee_percent' => 3, 'fee_fixed' => 0, 'settlement_days' => 1, 'active' => true,
        ]);
        $productA = $this->makeProduct(['name' => 'Item A', 'price' => 50, 'sku' => 'A-'.uniqid()]);
        $productB = $this->makeProduct(['name' => 'Item B', 'price' => 50, 'sku' => 'B-'.uniqid()]);

        $this->openRegisterFor($this->receptionist);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'product', 'sellable_id' => $productA->id, 'staff_id' => $this->groomerMember->id,
        ])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'product', 'sellable_id' => $productB->id, 'staff_id' => $this->groomerMember->id,
        ])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $card->id, 'amount' => 100])
            ->assertCreated();

        $open = $this->openCommissionsFor($this->groomerMember->id);

        $open->assertJsonPath('data.0.base_amount', 48.5)
            ->assertJsonPath('data.0.commission_amount', 4.85)
            ->assertJsonPath('data.1.base_amount', 48.5);
    }

    public function test_margin_base_is_zero_when_a_product_sells_for_exactly_its_average_cost(): void
    {
        $this->makeCommissionRule(['calculation_base' => 'margin', 'percent' => 20]);
        $product = $this->makeProduct(['price' => 100, 'average_cost' => 100]);

        $this->paySale([[
            'sellable_type' => 'product', 'sellable_id' => $product->id, 'staff_id' => $this->groomerMember->id,
        ]]);

        $this->openCommissionsFor($this->groomerMember->id)
            ->assertJsonPath('data.0.base_amount', 0)
            ->assertJsonPath('data.0.commission_amount', 0);
    }

    public function test_margin_base_for_a_service_equals_gross_because_services_have_no_unit_cost(): void
    {
        $this->makeCommissionRule(['calculation_base' => 'margin', 'percent' => 20]);
        $service = $this->makeService(['price' => 60]);

        $this->paySale([[
            'sellable_type' => 'service', 'sellable_id' => $service->id, 'staff_id' => $this->groomerMember->id,
        ]]);

        $this->openCommissionsFor($this->groomerMember->id)
            ->assertJsonPath('data.0.base_amount', 60)
            ->assertJsonPath('data.0.commission_amount', 12);
    }

    private function paySaleWithDiscount(Product $product, float $discount): void
    {
        $this->openRegisterFor($this->receptionist);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'product', 'sellable_id' => $product->id,
            'staff_id' => $this->groomerMember->id, 'discount' => $discount,
        ])->assertCreated();

        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $this->postJson("/api/professional/sales/{$saleId}/receipts", [
            'payment_method_id' => $cash, 'amount' => (float) $product->price - $discount,
        ])->assertCreated();
    }

    private function openCommissionsFor(int $staffId): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/professional/commissions/open?staff_id={$staffId}&received_until=".now()->toDateString())
            ->assertOk();
    }
}
