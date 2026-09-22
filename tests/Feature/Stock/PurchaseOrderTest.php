<?php

namespace Tests\Feature\Stock;

use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Stock\Concerns\BuildsStockFixtures;
use Tests\TestCase;

/** Pedido de compra → recebimento parcial → compra (doc 06). */
class PurchaseOrderTest extends TestCase
{
    use BuildsStockFixtures;
    use RefreshDatabase;

    public function test_partial_receipt_creates_a_draft_purchase_and_updates_the_order_when_received(): void
    {
        $this->buildStockClinic();
        $supplier = Supplier::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'legal_name' => 'Fornecedor']);
        $product = $this->product(['last_cost' => 7]);
        $this->actingAs($this->owner, 'sanctum');

        $order = $this->postJson('/api/professional/purchase-orders', [
            'supplier_id' => $supplier->id,
            'expected_at' => '2026-10-01',
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ])->assertCreated()->assertJsonPath('data.code', 1)->assertJsonPath('data.total', 70)->json('data');

        $this->postJson("/api/professional/purchase-orders/{$order['id']}/send")->assertOk()->assertJsonPath('data.status', 'sent');

        $purchaseId = $this->postJson("/api/professional/purchase-orders/{$order['id']}/receive", [
            'items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => 4]],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.purchase_order_id', $order['id'])
            ->assertJsonPath('data.items.0.quantity', 4)
            ->json('data.id');

        // O pedido só muda quando a COMPRA é efetivada.
        $this->getJson("/api/professional/purchase-orders/{$order['id']}")->assertJsonPath('data.status', 'sent');

        $this->postJson("/api/professional/purchases/{$purchaseId}/receive")->assertOk();

        $this->getJson("/api/professional/purchase-orders/{$order['id']}")
            ->assertJsonPath('data.status', 'partially_received')
            ->assertJsonPath('data.items.0.received_quantity', 4)
            ->assertJsonPath('data.items.0.pending_quantity', 6);
        $this->assertSame(4, $product->fresh()->stock_quantity);

        // Cancelar a compra devolve o pedido ao estado anterior.
        $this->postJson("/api/professional/purchases/{$purchaseId}/cancel", ['reason' => 'erro'])->assertOk();
        $this->getJson("/api/professional/purchase-orders/{$order['id']}")
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.items.0.received_quantity', 0);
    }
}
