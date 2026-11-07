<?php

namespace Tests\Feature\Commercial\Promotions;

use App\Models\Brand;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Promotion;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Promoções (docs/gap-petflow/PLANO.md, 3.2): CRUD com permissão pelo cargo, simulação com MC
 * negativa, avaliação do carrinho (automática e por `pdv/cart/evaluate`), venda paga gravando
 * `promotion_discount`/`promotion_id`, desconto manual por cima respeitando teto e custo,
 * rentabilidade, promoção vencida/inativa e outra organização.
 */
class PromotionTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 15:00:00');
        $this->buildClinic();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_crud_needs_promotions_manage_in_the_organization(): void
    {
        $shampoo = $this->makeProduct(['name' => 'Shampoo', 'price' => 40]);
        $body = $this->buyThreePayTwo($shampoo->id);

        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/promotions', $body)->assertCreated()
            ->assertJsonPath('data.type', 'buy_x_pay_y')->assertJsonPath('data.config.buy', 3)->json('data.id');
        $this->putJson("/api/professional/promotions/{$id}", ['priority' => 5, 'config' => ['buy' => 4, 'pay' => 3, 'targets' => [['type' => 'product', 'id' => $shampoo->id]]]])
            ->assertOk()->assertJsonPath('data.priority', 5)->assertJsonPath('data.config.buy', 4);
        $this->getJson('/api/professional/promotions')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/professional/promotions', ['type' => 'bundle', 'config' => ['items' => []]] + $body)->assertUnprocessable()->assertJsonValidationErrors(['config.items']);
        $this->postJson('/api/professional/promotions', $this->buyThreePayTwo($shampoo->id, ['buy' => 2, 'pay' => 2]))->assertUnprocessable()->assertJsonValidationErrors('config.pay');

        // Recepcionista (vet volante no papel global) não gere promoção da clínica.
        $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/promotions', $body)->assertForbidden();
        $this->actingAs($this->receptionist, 'sanctum')->getJson('/api/professional/promotions')->assertForbidden();

        $this->actingAs($this->owner, 'sanctum')->deleteJson("/api/professional/promotions/{$id}")->assertOk();
        $this->assertSoftDeleted('promotions', ['id' => $id]);
    }

    public function test_simulate_flags_a_negative_margin(): void
    {
        $shampoo = $this->makeProduct(['name' => 'Shampoo', 'price' => 40, 'average_cost' => '30.0000']);

        // 3 × 40 = 120, 1 grátis → venda 80; custo 90 → MC −10,00.
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/promotions/simulate', $this->buyThreePayTwo($shampoo->id))->assertOk()
            ->assertJsonPath('data.cost_total', '90.00')->assertJsonPath('data.sale_total', '80.00')->assertJsonPath('data.discount_total', '40.00')
            ->assertJsonPath('data.contribution_margin', '-10.00')->assertJsonPath('data.margin_percent', '-12.50')
            ->assertJsonPath('data.alert', 'A promoção dá prejuízo: a venda com desconto não cobre o custo.');
    }

    public function test_cart_is_evaluated_automatically_and_the_paid_sale_keeps_promotion_discount_and_id(): void
    {
        $shampoo = $this->makeProduct(['name' => 'Shampoo', 'price' => 40, 'average_cost' => '10.0000', 'controls_stock' => false]);
        $promotionId = $this->promotion($this->buyThreePayTwo($shampoo->id));
        $saleId = $this->newSale();

        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $shampoo->id, 'quantity' => 7])->assertCreated()
            ->assertJsonPath('data.promotion_id', $promotionId)->assertJsonPath('data.promotion_discount', '80.00')
            ->assertJsonPath('sale.total', 200)->assertJsonPath('sale.items.0.promotion_name', 'Leve 3 pague 2');

        $this->postJson('/api/professional/pdv/cart/evaluate', ['sale_id' => $saleId])->assertOk()
            ->assertJsonPath('data.lines.0.promotion_discount', '80.00')->assertJsonPath('data.total_promotion_discount', '80.00')->assertJsonPath('data.sale_total', '200.00');

        // Tirar duas unidades: 5 → 1 conjunto → 40,00.
        $itemId = Sale::findOrFail($saleId)->items()->value('id');
        $this->patchJson("/api/professional/sales/{$saleId}/items/{$itemId}", ['quantity' => 5])->assertOk()->assertJsonPath('data.promotion_discount', '40.00');

        $this->pay($saleId, 160);
        $item = Sale::findOrFail($saleId)->items()->sole();
        $this->assertSame([$promotionId, '40.00', '160.00'], [$item->promotion_id, (string) $item->promotion_discount, (string) $item->total]);

        // Rentabilidade sobre o líquido com promoção: 160 − 5 × 10 = 110.
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/sales/{$saleId}/profitability")->assertOk()
            ->assertJsonPath('data.totals.net_revenue', '160.00')->assertJsonPath('data.totals.cost_amount', '50.00');
    }

    public function test_manual_discount_on_top_respects_the_ceiling_and_the_cost(): void
    {
        $food = $this->makeProduct(['name' => 'Ração', 'price' => 100, 'average_cost' => '80.0000', 'controls_stock' => false, 'max_discount_percent' => '15']);
        $group = \App\Models\ProductGroup::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'Rações', 'active' => true]);
        $food->update(['product_group_id' => $group->id]);
        $this->promotion(['name' => 'Rações 10%', 'type' => 'category_discount', 'starts_at' => '2026-11-01', 'config' => ['group_id' => $group->id, 'percent' => 10]]);
        $saleId = $this->newSale();
        $itemId = $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $food->id])->assertCreated()
            ->assertJsonPath('data.promotion_discount', '10.00')->json('data.id');

        // Teto 15%: promoção 10% + manual 6% = 16% → recusa; manual 5% = 15% → aceita.
        $this->patchJson("/api/professional/sales/{$saleId}/items/{$itemId}", ['discount' => 6])->assertUnprocessable()->assertJsonValidationErrors("items.{$itemId}.discount");
        $this->patchJson("/api/professional/sales/{$saleId}/items/{$itemId}", ['discount' => 5])->assertOk()->assertJsonPath('data.total', 85);
        // Líquido 85 ≥ custo 80: recebe.
        $this->pay($saleId, 85);

        // Promoção sozinha abaixo do custo passa; com desconto manual por cima, não.
        $cheap = $this->makeProduct(['name' => 'Petisco', 'price' => 10, 'average_cost' => '9.5000', 'controls_stock' => false, 'product_group_id' => $group->id]);
        $second = $this->newSale();
        $line = $this->postJson("/api/professional/sales/{$second}/items", ['sellable_type' => 'product', 'sellable_id' => $cheap->id])->assertCreated()->assertJsonPath('data.total', 9)->json('data.id');
        $this->patchJson("/api/professional/sales/{$second}/items/{$line}", ['discount' => 1])->assertOk();
        $this->actingAs($this->receptionist, 'sanctum')->postJson("/api/professional/sales/{$second}/receipts", ['payment_method_id' => $this->paymentMethodId($this->receptionist, 'cash'), 'amount' => 8])
            ->assertUnprocessable()->assertJsonValidationErrors("items.{$line}.unit_price");
        $this->patchJson("/api/professional/sales/{$second}/items/{$line}", ['discount' => 0])->assertOk();
        $this->pay($second, 9);
    }

    public function test_expired_inactive_and_other_organization_promotions_do_not_apply(): void
    {
        $shampoo = $this->makeProduct(['name' => 'Shampoo', 'price' => 40, 'controls_stock' => false]);
        $this->promotion($this->buyThreePayTwo($shampoo->id, [], ['ends_at' => '2026-11-01']));
        $this->promotion($this->buyThreePayTwo($shampoo->id, [], ['active' => false]));
        $this->promotion($this->buyThreePayTwo($shampoo->id, [], ['starts_at' => '2026-11-03']));

        $other = Organization::factory()->create();
        $stranger = User::factory()->professional()->create();
        OrganizationMember::factory()->owner()->for($other, 'organization')->create(['user_id' => $stranger->id]);
        Promotion::create(['organization_id' => $other->id, 'professional_id' => $stranger->id, 'name' => 'Deles', 'type' => 'buy_x_pay_y', 'starts_at' => '2026-11-01',
            'config' => ['buy' => 3, 'pay' => 2, 'targets' => [['type' => 'product', 'id' => $shampoo->id]]]]);

        $saleId = $this->newSale();
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $shampoo->id, 'quantity' => 3])->assertCreated()
            ->assertJsonPath('data.promotion_id', null)->assertJsonPath('data.promotion_discount', '0.00');

        // Outra organização não vê, não edita e não usa produto alheio como alvo.
        $mine = Promotion::where('organization_id', $this->clinic->id)->value('id');
        $this->actingAs($stranger, 'sanctum')->getJson('/api/professional/promotions')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($stranger, 'sanctum')->putJson("/api/professional/promotions/{$mine}", ['priority' => 9])->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->postJson('/api/professional/promotions', $this->buyThreePayTwo($shampoo->id))->assertUnprocessable()->assertJsonValidationErrors('config.targets.0.id');
        $this->actingAs($stranger, 'sanctum')->postJson('/api/professional/pdv/cart/evaluate', ['sale_id' => $saleId])->assertNotFound();
    }

    public function test_brand_and_bundle_promotions_in_the_cart(): void
    {
        $brand = Brand::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'name' => 'PetBrand', 'active' => true]);
        $food = $this->makeProduct(['name' => 'Ração', 'price' => 200, 'controls_stock' => false]);
        $flea = $this->makeProduct(['name' => 'Antipulgas', 'price' => 100, 'controls_stock' => false, 'brand_id' => $brand->id]);
        $this->promotion(['name' => 'Combo', 'type' => 'bundle', 'starts_at' => '2026-11-01', 'priority' => 2, 'config' => [
            'items' => [['sellable_type' => 'product', 'sellable_id' => $food->id, 'quantity' => 1], ['sellable_type' => 'product', 'sellable_id' => $flea->id, 'quantity' => 1]],
            'discount_type' => 'percent', 'discount_value' => 10,
        ]]);
        $this->promotion(['name' => 'Marca 5%', 'type' => 'brand_discount', 'starts_at' => '2026-11-01', 'priority' => 1, 'stackable' => true, 'config' => ['brand_id' => $brand->id, 'percent' => 5]]);

        $saleId = $this->newSale();
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $food->id])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $flea->id])->assertCreated();

        // Combo 10% = 30 (20 / 10); marca 5% cumulativa sobre os 90 que restam do antipulgas = 4,50.
        $lines = collect($this->postJson('/api/professional/pdv/cart/evaluate', ['sale_id' => $saleId])->assertOk()->assertJsonPath('data.total_promotion_discount', '34.50')->json('data.lines'))->keyBy('sale_item_id');
        $this->assertSame(['20.00', '14.50'], $lines->pluck('promotion_discount')->values()->all());
    }

    /** @param  array<string, mixed>  $config  @param  array<string, mixed>  $extra */
    private function buyThreePayTwo(int $productId, array $config = [], array $extra = []): array
    {
        return $extra + ['name' => 'Leve 3 pague 2', 'type' => 'buy_x_pay_y', 'starts_at' => '2026-11-01', 'config' => $config + ['buy' => 3, 'pay' => 2, 'targets' => [['type' => 'product', 'id' => $productId]]]];
    }

    /** @param  array<string, mixed>  $body */
    private function promotion(array $body): int
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/promotions', $body)->assertCreated()->json('data.id');
    }

    private bool $registerOpen = false;

    private function newSale(): int
    {
        if (! $this->registerOpen) {
            $this->openRegisterFor($this->receptionist);
            $this->registerOpen = true;
        }

        return $this->actingAs($this->receptionist, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
    }

    private function pay(int $saleId, float $amount): TestResponse
    {
        return $this->actingAs($this->receptionist, 'sanctum')->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($this->receptionist, 'cash'), 'amount' => $amount])
            ->assertCreated();
    }
}
