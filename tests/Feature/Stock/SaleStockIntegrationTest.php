<?php

namespace Tests\Feature\Stock;

use App\Enums\StockMovementType;
use App\Models\CashRegisterMovement;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Integração venda (doc 01) × estoque (doc 07): "venda de 2 unidades gera 1 movimento
 * `sale_out`", "cancelar a venda estorna com `return_in`, não apagando o original", e a
 * devolução parcial com estorno no caixa.
 */
class SaleStockIntegrationTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();

        $this->product = $this->makeProduct(['price' => 45, 'stock_quantity' => 0, 'average_cost' => 20]);
        app(StockService::class)->in($this->product, StockMovementType::OPENING_BALANCE, 10, ['unit_cost' => 20]);
    }

    public function test_paid_sale_writes_one_sale_out_and_cancel_writes_return_in(): void
    {
        $saleId = $this->paidSale(2);

        $out = StockMovement::where('type', 'sale_out')->sole();
        $this->assertSame(2, $out->quantity);
        $this->assertSame(8, $out->balance_after);
        $this->assertSame($saleId, $out->reference_id);
        $this->assertSame(8, $this->product->fresh()->stock_quantity);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Cliente desistiu'])->assertOk();

        $this->assertSame(10, $this->product->fresh()->stock_quantity);
        $this->assertSame(['opening_balance', 'sale_out', 'return_in'], StockMovement::orderBy('id')->pluck('type')->map->value->all());
        $this->assertLedger();
    }

    public function test_partial_return_restocks_refunds_cash_and_cancel_does_not_double_restock(): void
    {
        $saleId = $this->paidSale(3);
        $lookup = $this->getJson('/api/professional/sale-returns/sale-lookup?number=1')->assertOk();
        $itemId = $lookup->json('data.items.0.id');

        $this->postJson('/api/professional/sale-returns', [
            'sale_id' => $saleId, 'reason' => 'Embalagem violada', 'refund_method' => 'cash',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.total', 45);

        $this->assertSame(8, $this->product->fresh()->stock_quantity);
        $this->assertTrue(CashRegisterMovement::where('type', 'refund')->where('amount', 45)->exists());

        // Não devolve mais do que foi vendido.
        $this->postJson('/api/professional/sale-returns', [
            'sale_id' => $saleId, 'reason' => 'x', 'refund_method' => 'none',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 3]],
        ])->assertStatus(422);

        $this->getJson('/api/professional/sale-returns/sale-lookup?number=1')->assertJsonPath('data.items.0.returned_quantity', 1);

        // Cancelamento só devolve as 2 unidades que ainda estavam com o cliente.
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Cancelada'])->assertOk();
        $this->assertSame(10, $this->product->fresh()->stock_quantity);
        $this->assertLedger();
    }

    private function paidSale(int $quantity): int
    {
        $this->openRegisterFor($this->receptionist);
        $cash = $this->paymentMethodId($this->receptionist, 'cash');

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'product', 'sellable_id' => $this->product->id, 'quantity' => $quantity,
        ])->assertCreated();
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 45 * $quantity])
            ->assertCreated();

        return $saleId;
    }

    private function assertLedger(): void
    {
        $sum = StockMovement::where('product_id', $this->product->id)->get()
            ->sum(fn (StockMovement $m) => $m->signedQuantity());

        $this->assertSame((int) $this->product->fresh()->stock_quantity, (int) $sum);
    }
}
