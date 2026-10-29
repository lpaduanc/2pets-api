<?php

namespace Tests\Feature\Stock;

use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Stock\StockService;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Valor do estoque nunca aparece nem some sem movimento (docs/gap-petflow/PLANO.md, 0.2):
 * Σ entradas + ajustes a crédito = Σ saídas + ajustes a débito + `stock_value`, exato — pelo
 * fluxo real (endpoints e banco), nos cenários que a revisão de centavo encontrou.
 */
class StockValueConservationTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
    }

    public function test_cancelled_sale_restocks_at_the_value_that_left_not_the_frozen_cost(): void
    {
        // 1 un a 10 → item lançado (custo congelado 10) → chega 1 por 30 (médio 20) → paga (sai
        // 20) → cancela: volta 20, não 10. Ficam 2 un valendo 40.
        $product = $this->productWith(1, '10');
        $saleId = $this->saleWithItem($product, 1, 100);
        app(StockService::class)->in($product, StockMovementType::PURCHASE_IN, 1, ['total_cost' => '30']);
        $this->payInCash($saleId, 100);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/sales/{$saleId}/cancel", ['reason' => 'Desistiu'])->assertOk();

        $product->refresh();
        $this->assertSame('2.0000', $product->stock_quantity);
        $this->assertSame('40.00', $product->stock_value);
        $this->assertSame('20.00', $product->average_cost);
        $this->assertValueConserved($product);
    }

    public function test_refund_with_header_discount_returns_what_the_client_paid(): void
    {
        // R$ 100 com 10% no cabeçalho: o cliente pagou 90, e é 90 que volta — não 100.
        $product = $this->productWith(5, '20');
        $saleId = $this->saleWithItem($product, 1, 100);
        $this->postJson("/api/professional/sales/{$saleId}/discount", ['discount_type' => 'percent', 'discount_value' => 10])->assertOk();
        $this->payInCash($saleId, 90);
        $itemId = $this->getJson('/api/professional/sale-returns/sale-lookup?number=1')->json('data.items.0.id');

        $this->postJson('/api/professional/sale-returns', [
            'sale_id' => $saleId, 'reason' => 'Arrependimento', 'refund_method' => 'cash',
            'items' => [['sale_item_id' => $itemId, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('data.total', 90);

        $this->assertValueConserved($product->refresh());
    }

    public function test_count_shortage_across_batches_values_every_piece(): void
    {
        // Lotes A (3) e B (3) a 10; contados 2 → faltam 4, que saem em dois pedaços (3 + 1).
        // O valor do ajuste é −40, não só o último pedaço (−10).
        $product = $this->makeProduct(['stock_quantity' => 0, 'average_cost' => 10, 'track_batches' => true]);
        $stock = app(StockService::class);
        $stock->in($product, StockMovementType::PURCHASE_IN, 3, ['total_cost' => '30', 'batch_code' => 'A', 'expires_at' => '2027-01-01']);
        $stock->in($product, StockMovementType::PURCHASE_IN, 3, ['total_cost' => '30', 'batch_code' => 'B', 'expires_at' => '2027-06-01']);

        $this->actingAs($this->owner, 'sanctum');
        $countId = $this->postJson('/api/professional/stock-counts', ['notes' => 'Mensal'])->assertCreated()->json('data.id');
        $this->putJson("/api/professional/stock-counts/{$countId}/items", ['items' => [
            ['product_id' => $product->id, 'counted_quantity' => 2],
        ]])->assertOk();

        $this->postJson("/api/professional/stock-counts/{$countId}/close")->assertOk()
            ->assertJsonPath('data.adjustment_value', -40);

        $this->assertValueConserved($product->refresh());
    }

    public function test_saving_the_product_form_does_not_revalue_unless_the_average_changes(): void
    {
        // 3 un por R$ 10,00 (médio 3,33). Salvar o cadastro com o mesmo médio não mexe
        // no valor; mudar o médio para 5 gera um ajuste de custo de +5 no kardex.
        $product = $this->makeProduct(['stock_quantity' => 0, 'average_cost' => 0]);
        app(StockService::class)->in($product, StockMovementType::PURCHASE_IN, 3, ['total_cost' => '10']);
        $average = $product->fresh()->average_cost;

        $this->actingAs($this->owner, 'sanctum')
            ->putJson("/api/professional/products/{$product->id}", ['name' => 'Ração renomeada', 'average_cost' => $average])
            ->assertOk();

        $this->assertSame('10.00', $product->fresh()->stock_value);
        $this->assertFalse(StockMovement::where('product_id', $product->id)->where('type', 'cost_variance_in')->exists());

        $this->putJson("/api/professional/products/{$product->id}", ['average_cost' => '5'])->assertOk();

        $this->assertSame('15.00', $product->fresh()->stock_value);
        $this->assertSame('5.00', StockMovement::where('product_id', $product->id)->where('type', 'cost_variance_in')->sole()->total_cost);
        $this->assertValueConserved($product->refresh());
    }

    private function productWith(int $quantity, string $unitCost): Product
    {
        $product = $this->makeProduct(['price' => 100, 'stock_quantity' => 0, 'average_cost' => $unitCost]);
        app(StockService::class)->in($product, StockMovementType::OPENING_BALANCE, $quantity, ['unit_cost' => $unitCost]);

        return $product->refresh();
    }

    private function saleWithItem(Product $product, int $quantity, int $price): int
    {
        $this->openRegisterFor($this->receptionist);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'product', 'sellable_id' => $product->id, 'quantity' => $quantity, 'unit_price' => $price,
        ])->assertCreated();

        return $saleId;
    }

    private function payInCash(int $saleId, int $amount): void
    {
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => $amount])->assertCreated();
    }

    /** Σ valor com sinal dos movimentos (entradas e créditos +, saídas e débitos −) = `stock_value`. */
    private function assertValueConserved(Product $product): void
    {
        $sum = StockMovement::where('product_id', $product->id)->get()->reduce(
            fn (BigDecimal $total, StockMovement $m) => $total->plus(BigDecimal::of($m->total_cost)->multipliedBy($m->direction->sign())),
            BigDecimal::zero(),
        );

        $this->assertTrue($sum->isEqualTo($product->stock_value), "Movimentos somam {$sum}; estoque vale {$product->stock_value}.");
    }
}
