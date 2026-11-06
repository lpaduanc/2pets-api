<?php

namespace Tests\Feature\Commercial;

use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * O PDV cria a venda no primeiro item. Esvaziar o carrinho deixava uma venda aberta e vazia na
 * lista de vendas: remover o último item de um rascunho sem histórico agora descarta a venda.
 */
class SaleEmptyDraftDiscardTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
        $this->actingAs($this->receptionist, 'sanctum');
        $this->openRegisterFor($this->receptionist, 0);
    }

    public function test_removing_the_last_item_of_an_empty_draft_discards_the_sale(): void
    {
        $product = $this->makeProduct(['price' => 10, 'stock_quantity' => 5]);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $itemId = $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id])
            ->assertCreated()->json('data.id');

        $this->deleteJson("/api/professional/sales/{$saleId}/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('discarded', true)
            ->assertJsonPath('sale', null);

        $this->assertSoftDeleted('sales', ['id' => $saleId]);
        $this->assertNotContains($saleId, collect($this->getJson('/api/professional/sales')->json('data'))->pluck('id'));
    }

    public function test_removing_an_item_that_is_not_the_last_keeps_the_sale(): void
    {
        $product = $this->makeProduct(['price' => 10, 'stock_quantity' => 5]);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $first = $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id])->json('data.id');
        $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id])->assertCreated();

        $this->deleteJson("/api/professional/sales/{$saleId}/items/{$first}")
            ->assertOk()
            ->assertJsonPath('discarded', false)
            ->assertJsonPath('sale.id', $saleId);
    }

    public function test_sale_with_a_receipt_history_is_never_discarded(): void
    {
        $cash = $this->paymentMethodId($this->receptionist, 'cash');
        $product = $this->makeProduct(['price' => 10, 'stock_quantity' => 5]);
        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->assertCreated()->json('data.id');
        $first = $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id])->json('data.id');
        $second = $this->postJson("/api/professional/sales/{$saleId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id])->json('data.id');
        $receiptId = $this->postJson("/api/professional/sales/{$saleId}/receipts", ['payment_method_id' => $cash, 'amount' => 5])
            ->assertCreated()->json('data.id');
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/professional/sales/{$saleId}/receipts/{$receiptId}/cancel", ['reason' => 'Lançado por engano'])
            ->assertOk();

        $this->actingAs($this->receptionist, 'sanctum')->deleteJson("/api/professional/sales/{$saleId}/items/{$first}")->assertOk();
        $this->deleteJson("/api/professional/sales/{$saleId}/items/{$second}")
            ->assertOk()
            ->assertJsonPath('discarded', false);

        $this->assertNotSoftDeleted('sales', ['id' => $saleId]);
    }

    public function test_quote_is_never_discarded(): void
    {
        $product = $this->makeProduct(['price' => 10, 'stock_quantity' => 5]);
        $quoteId = $this->postJson('/api/professional/sales', ['kind' => 'quote'])->assertCreated()->json('data.id');
        $itemId = $this->postJson("/api/professional/sales/{$quoteId}/items", ['sellable_type' => 'product', 'sellable_id' => $product->id])->json('data.id');

        $this->deleteJson("/api/professional/sales/{$quoteId}/items/{$itemId}")->assertOk()->assertJsonPath('discarded', false);

        $this->assertNotNull(Sale::find($quoteId));
    }
}
