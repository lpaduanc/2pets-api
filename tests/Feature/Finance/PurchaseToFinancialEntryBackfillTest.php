<?php

namespace Tests\Feature\Finance;

use App\Models\FinancialEntry;
use App\Models\PaymentMethod;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Stock\Concerns\BuildsStockFixtures;
use Tests\TestCase;

/**
 * Compra recebida → `financial_entries` de despesa 1:1 com `purchase_installments` — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * A migration de backfill (`2026_10_20_300003_...`) roda sobre dado pré-existente do banco
 * real; ela não é testável isolada aqui porque `RefreshDatabase` executa TODAS as migrations
 * contra um banco vazio (não há "compra antiga" no schema no momento em que ela roda). O que
 * este teste prova é a mesma regra de negócio da migration, aplicada por
 * `PurchaseFinancialEntryRecorder` no fluxo vivo (`PurchaseService::receive()`), que é o
 * caminho que passa a valer a partir desta entrega.
 */
class PurchaseToFinancialEntryBackfillTest extends TestCase
{
    use BuildsStockFixtures;
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildStockClinic();
        $this->supplier = Supplier::create([
            'organization_id' => $this->clinic->id,
            'professional_id' => $this->owner->id,
            'legal_name' => 'Distribuidora X Ltda',
            'document' => '12.345.678/0001-90',
        ]);
    }

    public function test_receiving_a_purchase_with_installments_links_one_financial_entry_per_installment(): void
    {
        $product = $this->product(['price' => 30]);
        $method = PaymentMethod::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'name' => 'Boleto', 'kind' => 'boleto', 'direction' => 'out',
        ]);

        $purchaseId = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/purchases', [
                'supplier_id' => $this->supplier->id,
                'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 20]],
                'payment' => ['payment_method_id' => $method->id, 'installments' => 2, 'first_due_date' => '2026-10-20'],
            ])->assertCreated()->json('data.id');

        $this->postJson("/api/professional/purchases/{$purchaseId}/receive")->assertOk();

        $purchase = Purchase::findOrFail($purchaseId);
        $installments = $purchase->installments()->orderBy('number')->get();

        $this->assertCount(2, $installments);
        $this->assertTrue($installments->every(fn ($i) => $i->financial_entry_id !== null));

        $entries = FinancialEntry::whereIn('id', $installments->pluck('financial_entry_id'))->orderBy('installment_number')->get();
        $this->assertSame(2, $entries->count());
        $this->assertSame($entries->first()->series_id, $entries->last()->series_id);
        $this->assertNotNull($entries->first()->series_id);
        $this->assertSame('expense', $entries->first()->nature->value);
        $this->assertSame('open', $entries->first()->status->value);
        $this->assertEqualsWithDelta(100.0, (float) $entries->sum('net_amount'), 0.01);
        $this->assertSame($this->supplier->id, $entries->first()->supplier_id);
    }

    public function test_cancelling_a_received_purchase_cancels_its_open_financial_entries(): void
    {
        $product = $this->product(['price' => 30]);
        $method = PaymentMethod::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'name' => 'Boleto', 'kind' => 'boleto', 'direction' => 'out',
        ]);

        $purchaseId = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/purchases', [
                'supplier_id' => $this->supplier->id,
                'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 20]],
                'payment' => ['payment_method_id' => $method->id, 'installments' => 1, 'first_due_date' => '2026-10-20'],
            ])->assertCreated()->json('data.id');

        $this->postJson("/api/professional/purchases/{$purchaseId}/receive")->assertOk();
        $this->postJson("/api/professional/purchases/{$purchaseId}/cancel", ['reason' => 'Nota errada'])->assertOk();

        $entry = FinancialEntry::where('reference_type', 'purchase_installment')->sole();
        $this->assertSame('cancelled', $entry->status->value);
    }
}
