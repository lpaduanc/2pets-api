<?php

namespace Tests\Feature\Stock;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Stock\Concerns\BuildsStockFixtures;
use Tests\TestCase;

/**
 * Critério de aceite do doc 07: "todo saldo é reconstituível somando `stock_movements`" —
 * por todos os caminhos que mexem em saldo fora da venda (cadastro, lançamento manual,
 * inventário).
 */
class StockLedgerIntegrityTest extends TestCase
{
    use BuildsStockFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildStockClinic();
    }

    public function test_stock_informed_on_product_creation_becomes_an_opening_balance(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/products', [
                'name' => 'Coleira antipulgas', 'sku' => 'COL-1', 'price' => 50, 'average_cost' => 20, 'stock_quantity' => 7,
            ])->assertCreated()->json('data.id');

        $product = Product::findOrFail($id);
        $this->assertSame(7, $product->stock_quantity);

        $movement = StockMovement::where('product_id', $id)->sole();
        $this->assertSame(StockMovementType::OPENING_BALANCE, $movement->type);
        $this->assertSame(7, $movement->balance_after);
        $this->assertLedgerMatches($product);
    }

    public function test_editing_stock_on_the_product_form_becomes_an_adjustment(): void
    {
        $product = $this->product();
        app(\App\Services\Stock\StockService::class)->in($product, StockMovementType::OPENING_BALANCE, 10);

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/products/{$product->id}", ['stock_quantity' => 6])
            ->assertOk()
            ->assertJsonPath('data.stock_quantity', 6);

        $last = StockMovement::where('product_id', $product->id)->latest('id')->first();
        $this->assertSame(StockMovementType::ADJUSTMENT_OUT, $last->type);
        $this->assertSame(4, $last->quantity);
        $this->assertSame($this->owner->id, $last->user_id);
        $this->assertLedgerMatches($product);
    }

    public function test_manual_exits_with_reason_and_kardex_shows_running_balance(): void
    {
        $product = $this->product(['average_cost' => 12.5]);
        $this->actingAs($this->owner, 'sanctum');

        $this->postJson('/api/professional/stock-movements', ['product_id' => $product->id, 'type' => 'adjustment_in', 'quantity' => 10])
            ->assertCreated();

        $reasonId = $this->getJson('/api/professional/stock-exit-reasons')->assertOk()->json('data.0.id');

        $this->postJson('/api/professional/stock-movements', [
            'product_id' => $product->id, 'type' => 'loss', 'quantity' => 3, 'reason_id' => $reasonId, 'notes' => 'Pacote rasgado',
        ])->assertCreated()->assertJsonPath('data.0.balance_after', 7)->assertJsonPath('data.0.total_cost', 37.5);

        $kardex = $this->getJson("/api/professional/products/{$product->id}/stock-movements")->assertOk();
        $this->assertSame([7, 10], array_column($kardex->json('data'), 'balance_after'));
        $kardex->assertJsonPath('product.stock_quantity', 7);

        $this->getJson('/api/professional/stock-movements?other_exits=1')->assertOk()->assertJsonCount(1, 'data');
        $this->assertLedgerMatches($product);
    }

    public function test_flow_owned_types_cannot_be_forged_manually(): void
    {
        $product = $this->product();

        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/stock-movements', ['product_id' => $product->id, 'type' => 'purchase_in', 'quantity' => 5])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_product_that_does_not_control_stock_generates_no_movement(): void
    {
        $product = $this->product(['controls_stock' => false]);

        $movements = app(\App\Services\Stock\StockService::class)->out($product, StockMovementType::SALE_OUT, 2);

        $this->assertSame([], $movements);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, $product->fresh()->stock_quantity);
    }

    public function test_another_clinic_cannot_see_or_move_the_stock(): void
    {
        $product = $this->product();
        $stranger = \App\Models\User::factory()->professional()->create();

        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/professional/stock-movements', ['product_id' => $product->id, 'type' => 'adjustment_in', 'quantity' => 5])
            ->assertNotFound();

        $this->getJson("/api/professional/products/{$product->id}/stock-movements")->assertNotFound();
    }
}
