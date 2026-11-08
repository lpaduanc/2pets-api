<?php

namespace Tests\Feature\Fiscal;

use App\Enums\StockMovementType;
use App\Models\FinancialEntry;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\TestCase;

/**
 * Devolução de compra (estoque) e a NF-e de saída ao fornecedor — spec docs/gap-petflow/specs/
 * fase4-fiscal-sem-envio.md §3.6 (golden `fiscal-compra-dev-01` pelo caminho real).
 */
class PurchaseReturnFiscalDocumentTest extends TestCase
{
    use BuildsCounterFixtures;
    use RefreshDatabase;

    private const INVOICE_KEY = '35261112345678000190550010000012341000012345';

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildClinic();
        $this->supplier = Supplier::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'legal_name' => 'Distribuidora Pet Ltda', 'document' => '12.345.678/0001-90', 'address_state' => 'SP',
        ]);
        $withSt = $this->makeTaxProfile(['name' => 'Com ST', 'icms_csosn' => '500'], ['return_to_supplier' => ['intrastate' => '5411', 'interstate' => '6411']]);
        $this->product = $this->makeProduct(['name' => 'Ração Premium', 'tax_profile_id' => $withSt->id, 'stock_quantity' => 0]);
    }

    public function test_golden_purchase_return_moves_stock_and_issues_the_outgoing_nfe(): void
    {
        $purchaseId = $this->receivedPurchase();
        $itemId = (int) PurchaseItem::where('purchase_id', $purchaseId)->value('id');
        $entriesBefore = FinancialEntry::count();
        $this->actingAs($this->owner, 'sanctum');

        $this->postJson("/api/professional/purchases/{$purchaseId}/returns/preview", ['items' => [['purchase_item_id' => $itemId, 'quantity' => 4]]])
            ->assertOk()
            ->assertJsonPath('data.0.products_total', '80.00')
            ->assertJsonPath('data.0.ipi_share', '4.00')
            ->assertJsonPath('data.0.icms_st_share', '6.00')
            ->assertJsonPath('data.0.total', '90.00');

        $returnId = $this->postJson('/api/professional/purchase-returns', ['purchase_id' => $purchaseId, 'reason' => 'Embalagem avariada', 'items' => [['purchase_item_id' => $itemId, 'quantity' => 4]]])
            ->assertCreated()
            ->assertJsonPath('data.total', '90.00')
            ->assertJsonPath('data.items.0.quantity', '4.0000')
            ->json('data.id');

        $this->assertSame('6.0000', $this->product->fresh()->stock_quantity);
        $this->assertSame('90.00', (string) StockMovement::where('type', StockMovementType::RETURN_OUT->value)->value('total_cost'));
        $this->assertSame($entriesBefore, FinancialEntry::count(), 'Devolução de compra não mexe no financeiro (decisão pendente).');

        $document = $this->postJson("/api/professional/purchase-returns/{$returnId}/fiscal-documents")->assertCreated()->json('data');
        $item = $document['items'][0];

        $this->assertSame(['nfe', 'purchase_return', 'out', null, $returnId, Purchase::findOrFail($purchaseId)->invoice_key], [$document['kind'], $document['operation'], $document['direction'], $document['sale_id'], $document['purchase_return_id'], $document['referenced_access_key']]);
        $this->assertSame(['5411', '500', '80.00', '4.00', '6.00', '90.00'], [$item['cfop'], $item['csosn'], $item['gross_total'], $item['ipi_amount'], $item['icms_st_amount'], $item['total']]);
        $this->assertEqualsWithDelta(90.0, $document['total'], 0.001);

        $second = $this->postJson('/api/professional/purchase-returns', ['purchase_id' => $purchaseId, 'reason' => 'Restante', 'items' => [['purchase_item_id' => $itemId, 'quantity' => 6]]])->assertCreated()->json('data');
        $this->assertSame(['120.00', '6.00', '9.00', '135.00'], [$second['items'][0]['products_total'], $second['items'][0]['ipi_share'], $second['items'][0]['icms_st_share'], $second['total']]);
        $this->postJson('/api/professional/purchase-returns', ['purchase_id' => $purchaseId, 'reason' => 'Demais', 'items' => [['purchase_item_id' => $itemId, 'quantity' => 1]]])->assertStatus(422);
    }

    public function test_interstate_supplier_gets_the_interstate_cfop(): void
    {
        $this->supplier->update(['address_state' => 'MG']);
        $returnId = $this->returnFrom($this->receivedPurchase());

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/purchase-returns/{$returnId}/fiscal-documents")->assertCreated()->assertJsonPath('data.items.0.cfop', '6411');
    }

    public function test_the_nfe_needs_the_purchase_invoice_key_and_the_supplier_state(): void
    {
        $returnId = $this->returnFrom($this->receivedPurchase(invoiceKey: null));
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/purchase-returns/{$returnId}/fiscal-documents")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Informe a chave de acesso (44 dígitos) da nota de compra para emitir a NF-e de devolução.');

        $this->supplier->update(['address_state' => null]);
        $other = $this->returnFrom($this->receivedPurchase());
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/professional/purchase-returns/{$other}/fiscal-documents")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Complete o cadastro do fornecedor para a NF-e de devolução: UF.');
    }

    public function test_only_received_purchases_can_be_returned_and_listing_shows_returns(): void
    {
        $draftId = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/purchases', $this->purchasePayload(self::INVOICE_KEY))->assertCreated()->json('data.id');
        $draftItem = (int) PurchaseItem::where('purchase_id', $draftId)->value('id');
        $this->postJson("/api/professional/purchases/{$draftId}/returns/preview", ['items' => [['purchase_item_id' => $draftItem, 'quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Só compra recebida pode ser devolvida ao fornecedor.');

        $purchaseId = $this->receivedPurchase();
        $returnId = $this->returnFrom($purchaseId);
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/professional/purchase-returns?purchase_id={$purchaseId}")->assertOk()->assertJsonPath('data.0.id', $returnId);
        $this->getJson("/api/professional/purchase-returns/{$returnId}")->assertOk()->assertJsonPath('data.purchase.invoice_key', Purchase::findOrFail($purchaseId)->invoice_key);
    }

    private function receivedPurchase(?string $invoiceKey = self::INVOICE_KEY): int
    {
        $id = $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/purchases', $this->purchasePayload($invoiceKey))->assertCreated()->json('data.id');
        $this->postJson("/api/professional/purchases/{$id}/receive")->assertOk();

        return $id;
    }

    /** @return array<string, mixed> */
    private function purchasePayload(?string $invoiceKey): array
    {
        return array_filter([
            'supplier_id' => $this->supplier->id,
            'invoice_number' => (string) random_int(1000, 9999),
            'invoice_key' => $invoiceKey === null ? null : substr($invoiceKey, 0, 40).random_int(1000, 9999),
            'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_cost' => 20, 'ipi_amount' => '10.00', 'icms_st_amount' => '15.00']],
        ], fn ($value): bool => $value !== null);
    }

    private function returnFrom(int $purchaseId): int
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/professional/purchase-returns', [
            'purchase_id' => $purchaseId, 'reason' => 'Avaria', 'items' => [['purchase_item_id' => (int) PurchaseItem::where('purchase_id', $purchaseId)->value('id'), 'quantity' => 2]],
        ])->assertCreated()->json('data.id');
    }
}
