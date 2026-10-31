<?php

namespace Tests\Feature\Commercial\Pricing;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Regras de preço da venda (docs/gap-petflow/PLANO.md, 1.4): desconto máximo por item (bloqueia
 * na hora) e venda abaixo do custo — lançar aceita e marca `below_cost`; a finalização recusa
 * para todos, dono, recepção e vet volante (decisão do dono do produto).
 *
 * Rateio do desconto do cabeçalho (maior resto, `Sale::netItemTotals`) — linhas A 100 − 5 = 95
 * (teto 10%) e B 100 (sem teto), subtotal 195:
 *  - R$ 10,00: 1000×95/195 = 487,18 e 1000×100/195 = 512,82 → 487 + 512, sobra a B → A 4,87:
 *    efetivo (5 + 4,87) / 100 = 9,87% ≤ 10%.
 *  - R$ 12,00: 584,62 e 615,38 → 584 + 615, sobra a A → A 5,85: efetivo 10,85% > 10% → 422.
 *  - 6%: desconto 11,70; A 1170×95/195 = 570 → 5,70: efetivo 10,70% → 422.
 */
class SalePricingRulesTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private int $saleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        $this->openRegisterFor($this->receptionist);
        $this->saleId = $this->actingAs($this->receptionist, 'sanctum')
            ->postJson('/api/professional/sales', ['kind' => 'sale'])
            ->assertCreated()
            ->json('data.id');
    }

    // ---------------------------------------------------------------- desconto máximo

    public function test_line_discount_up_to_the_ceiling_is_accepted_and_above_it_is_refused(): void
    {
        $product = $this->limitedProduct();

        $this->addItem($product, ['discount' => '10.00'])->assertCreated();
        $this->addItem($product, ['discount' => '10.01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discount' => 'Desconto de 10,01% em "Ração Premium 3kg" passa do máximo permitido de 10,00%.']);

        $this->assertSame(1, SaleItem::where('sale_id', $this->saleId)->count());
    }

    public function test_editing_a_line_above_the_ceiling_points_at_that_line(): void
    {
        $itemId = $this->addItem($this->limitedProduct(), ['discount' => '5.00'])->json('data.id');

        $this->patchJson("/api/professional/sales/{$this->saleId}/items/{$itemId}", ['discount' => '20.00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors("items.{$itemId}.discount");

        $this->assertSame('5.00', SaleItem::findOrFail($itemId)->discount);
    }

    public function test_header_discount_share_counts_towards_the_ceiling(): void
    {
        $this->addItem($this->limitedProduct(), ['discount' => '5.00'])->assertCreated();
        $this->addItem($this->makeProduct(['controls_stock' => false]))->assertCreated();

        $this->applyHeaderDiscount('amount', 10)->assertOk();
        $this->applyHeaderDiscount('amount', 12)
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount_value');
        $this->applyHeaderDiscount('percent', 6)
            ->assertStatus(422)
            ->assertJsonValidationErrors('discount_value');

        // O 422 desfaz a mudança: o desconto que vale é o de R$ 10,00.
        $sale = Sale::findOrFail($this->saleId);
        $this->assertSame('10.00', $sale->discount_amount);
        $this->assertSame('185.00', $sale->total);
    }

    public function test_receipt_rechecks_the_ceiling_after_the_catalog_changed(): void
    {
        $product = $this->limitedProduct();
        $itemId = $this->addItem($product, ['discount' => '8.00'])->json('data.id');
        $product->update(['max_discount_percent' => '5']);

        $this->postJson("/api/professional/sales/{$this->saleId}/receipts", [
            'payment_method_id' => $this->paymentMethodId($this->receptionist, 'cash'), 'amount' => '92.00',
        ])->assertStatus(422)->assertJsonValidationErrors("items.{$itemId}.discount");

        $this->assertSame(0, Sale::findOrFail($this->saleId)->receipts()->count());
    }

    public function test_product_without_ceiling_accepts_any_discount(): void
    {
        $this->addItem($this->makeProduct(['controls_stock' => false]), ['discount' => '90.00'])->assertCreated();
    }

    public function test_line_discount_above_the_gross_is_refused_on_post_patch_and_quantity_change(): void
    {
        $product = $this->makeProduct(['controls_stock' => false, 'price' => 42]);
        $this->addItem($product, ['discount' => '3000.00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['discount' => 'O desconto de "Ração Premium 3kg" passa do valor da linha (R$ 42,00). Para dar o item, marque como brinde, cortesia ou retorno.']);

        $itemId = $this->addItem($product, ['quantity' => 3, 'discount' => '100.00'])->assertCreated()->json('data.id');
        $url = "/api/professional/sales/{$this->saleId}/items/{$itemId}";
        $this->patchJson($url, ['discount' => '3000.00'])->assertStatus(422)->assertJsonValidationErrors("items.{$itemId}.discount");
        // 2 × 42 = 84 < 100 de desconto: baixar a quantidade também é recusado.
        $this->patchJson($url, ['quantity' => 2])->assertStatus(422)->assertJsonValidationErrors("items.{$itemId}.discount");

        $item = SaleItem::findOrFail($itemId);
        $this->assertSame(['3.0000', '100.00', '26.00'], [$item->quantity, $item->discount, $item->total]);
        // Igual ao bruto ainda passa (é desconto, não excesso).
        $this->patchJson($url, ['discount' => '126.00'])->assertOk();
    }

    public function test_header_discount_above_the_subtotal_or_one_hundred_percent_is_refused(): void
    {
        $this->addItem($this->makeProduct(['controls_stock' => false, 'price' => 42]))->assertCreated();

        $this->applyHeaderDiscount('amount', 42.01)->assertStatus(422)->assertJsonValidationErrors(['discount_value' => 'O desconto da venda passa do limite (R$ 42,00). Para dar itens, marque-os como brinde, cortesia ou retorno.']);
        $this->applyHeaderDiscount('percent', 100.01)->assertStatus(422)->assertJsonValidationErrors('discount_value');
        $this->assertSame('42.00', Sale::findOrFail($this->saleId)->total);

        $this->applyHeaderDiscount('percent', 100)->assertOk();
        $this->applyHeaderDiscount('amount', 42)->assertOk();
    }

    // ---------------------------------------------------------------- abaixo do custo

    public function test_below_cost_line_is_accepted_and_flagged_but_nobody_receives_it(): void
    {
        $message = '"Ração Premium 3kg" está abaixo do custo (R$ 79,99 por unidade, custo R$ 80,00). Corrija o preço ou marque como brinde, cortesia ou retorno.';
        $itemId = $this->addItem($this->costlyProduct(), ['unit_price' => '79.99'], $this->owner)
            ->assertCreated()
            ->assertJsonPath('data.below_cost', true)
            ->json('data.id');

        $this->receive('79.99')->assertStatus(422)->assertJsonValidationErrors(["items.{$itemId}.unit_price" => $message]);
        $this->openRegisterFor($this->owner);
        $this->receive('79.99', $this->owner)->assertStatus(422)->assertJsonValidationErrors("items.{$itemId}.unit_price");

        $this->patchJson("/api/professional/sales/{$this->saleId}/items/{$itemId}", ['unit_price' => '80.00'])
            ->assertOk()
            ->assertJsonPath('data.below_cost', false);
        $this->receive('80.00')->assertCreated();
    }

    public function test_freelance_vet_cannot_receive_below_cost_on_their_own_counter_either(): void
    {
        $vet = User::factory()->professional()->create();
        $this->openRegisterFor($vet);
        $saleId = $this->actingAs($vet, 'sanctum')->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $product = Product::create([
            'professional_id' => $vet->id, 'name' => 'Vermífugo', 'sku' => 'V-1', 'price' => 100, 'average_cost' => '80.0000',
            'controls_stock' => false, 'allow_price_override' => true, 'is_active' => true,
        ]);
        $itemId = $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id, 'unit_price' => '70.00'])
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($vet, 'cash'), 'amount' => '70.00'])
            ->assertStatus(422)
            ->assertJsonValidationErrors("items.{$itemId}.unit_price");
    }

    public function test_header_discount_below_cost_is_accepted_and_blocked_at_the_receipt(): void
    {
        $itemId = $this->addItem($this->costlyProduct(['average_cost' => '95.0000']))->assertCreated()->json('data.id');

        $this->applyHeaderDiscount('amount', 10)->assertOk()->assertJsonPath('data.items.0.below_cost', true);
        $this->receive('90.00')->assertStatus(422)->assertJsonValidationErrors("items.{$itemId}.unit_price");
    }

    public function test_removing_an_item_cannot_slip_a_line_below_cost_past_the_receipt(): void
    {
        // A 100 (custo 95) + B 100, desconto R$ 10,00: 5,00 para cada → A líquido 95 = custo, ok.
        $costlyId = $this->addItem($this->costlyProduct(['average_cost' => '95.0000']))->assertCreated()->json('data.id');
        $otherId = $this->addItem($this->makeProduct(['controls_stock' => false]))->assertCreated()->json('data.id');
        $this->applyHeaderDiscount('amount', 10)->assertOk();

        // Sem B, A leva o desconto inteiro: 90 < 95. A remoção passa; o recebimento, não — nem do dono.
        $this->deleteJson("/api/professional/sales/{$this->saleId}/items/{$otherId}")->assertOk();
        $this->receive('90.00')->assertStatus(422)->assertJsonValidationErrors("items.{$costlyId}.unit_price");
        $this->openRegisterFor($this->owner);
        $this->receive('90.00', $this->owner)->assertStatus(422)->assertJsonValidationErrors("items.{$costlyId}.unit_price");

        $this->assertSame(0, Sale::findOrFail($this->saleId)->receipts()->count());
    }

    /** @param  array<string, mixed>  $body */
    private function addItem(Product $product, array $body = [], ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->receptionist, 'sanctum')
            ->postJson("/api/professional/sales/{$this->saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id] + $body);
    }

    private function receive(string $amount, ?User $by = null): TestResponse
    {
        $by ??= $this->receptionist;

        return $this->actingAs($by, 'sanctum')
            ->postJson("/api/professional/sales/{$this->saleId}/receipts", ['payment_method_id' => $this->paymentMethodId($by, 'cash'), 'amount' => $amount]);
    }

    private function applyHeaderDiscount(string $type, float $value): TestResponse
    {
        return $this->postJson("/api/professional/sales/{$this->saleId}/discount", ['discount_type' => $type, 'discount_value' => $value]);
    }

    private function limitedProduct(): Product
    {
        return $this->makeProduct(['controls_stock' => false, 'max_discount_percent' => '10']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function costlyProduct(array $overrides = []): Product
    {
        return $this->makeProduct($overrides + ['controls_stock' => false, 'allow_price_override' => true, 'average_cost' => '80.0000']);
    }
}
