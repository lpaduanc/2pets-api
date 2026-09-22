<?php

namespace Tests\Feature\Stock;

use App\Enums\StockMovementType;
use App\Models\FinancialAccount;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Stock\Concerns\BuildsStockFixtures;
use Tests\TestCase;

/** Recebimento e cancelamento de compra — critérios de aceite do doc 06. */
class PurchaseReceiveTest extends TestCase
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
            'sales_rep_name' => 'Carlos',
            'sales_rep_phone' => '11999998888',
            'phone' => '1133334444',
        ]);
    }

    public function test_supplier_keeps_sales_rep_contact_apart_from_company_contact(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/professional/suppliers/{$this->supplier->id}")
            ->assertOk()
            ->assertJsonPath('data.document', '12345678000190')
            ->assertJsonPath('data.phone', '1133334444')
            ->assertJsonPath('data.sales_rep_name', 'Carlos')
            ->assertJsonPath('data.sales_rep_phone', '11999998888');
    }

    public function test_receiving_enters_stock_updates_average_cost_price_and_generates_installments(): void
    {
        $product = $this->product(['price' => 30, 'average_cost' => 20, 'markup_percent' => 50]);
        app(StockService::class)->in($product, StockMovementType::OPENING_BALANCE, 10, ['unit_cost' => 20]);

        [$method, $account] = $this->paymentSetup();
        $this->actingAs($this->owner, 'sanctum');

        $purchaseId = $this->postJson('/api/professional/purchases', [
            'supplier_id' => $this->supplier->id,
            'invoice_number' => '555',
            'entered_at' => '2026-09-20 10:00:00',
            'items' => [
                [
                    'product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 26,
                    'supplier_product_code' => 'X-9', 'markup_percent' => 50, 'applied_sale_price' => 39,
                ],
                [
                    'new_product' => ['name' => 'Petisco dental', 'controls_stock' => true],
                    'quantity' => 12, 'unit_cost' => 4, 'markup_percent' => 100, 'batch' => 'PD1', 'expires_at' => '2027-01-31',
                ],
            ],
            'payment' => [
                'payment_method_id' => $method->id, 'financial_account_id' => $account->id,
                'installments' => 3, 'first_due_date' => '2026-10-20',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total', 178)
            ->assertJsonPath('data.items.0.suggested_price', 39)
            ->assertJsonPath('data.items.1.suggested_price', 8)
            ->json('data.id');

        // Rascunho não mexe em estoque nem cria dívida.
        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertSame(0, Purchase::find($purchaseId)->installments()->count());

        $this->postJson("/api/professional/purchases/{$purchaseId}/receive")
            ->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonCount(3, 'data.installments')
            ->assertJsonPath('data.installments.0.due_date', '2026-10-20')
            ->assertJsonPath('data.installments.2.due_date', '2026-12-21')
            ->assertJsonPath('data.installments.2.amount', 59.34);

        $product->refresh();
        $this->assertSame(15, $product->stock_quantity);
        $this->assertSame(22.0, (float) $product->average_cost); // (10×20 + 5×26) / 15
        $this->assertSame(26.0, (float) $product->last_cost);
        $this->assertSame(39.0, (float) $product->price);
        $this->assertSame(50.0, (float) $product->markup_percent);
        $this->assertSame($this->supplier->id, $product->last_supplier_id);

        $created = Product::where('name', 'Petisco dental')->sole();
        $this->assertSame(12, $created->stock_quantity);
        $this->assertSame(4.0, (float) $created->average_cost);
        $this->assertSame(8.0, (float) $created->price);
        $this->assertSame(12, $created->batches()->where('batch_code', 'PD1')->value('quantity'));

        $this->assertSame(1, StockMovement::where('product_id', $product->id)->where('type', 'purchase_in')->count());
        $this->assertTrue(SupplierProduct::where('supplier_id', $this->supplier->id)->where('supplier_product_code', 'X-9')->where('product_id', $product->id)->exists());
        $this->assertLedgerMatches($product);
        $this->assertLedgerMatches($created);

        // Compra efetivada não se edita.
        $this->putJson("/api/professional/purchases/{$purchaseId}", [
            'supplier_id' => $this->supplier->id, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1]],
        ])->assertStatus(422);
    }

    public function test_cancelling_a_received_purchase_reverses_stock_cost_and_open_installments(): void
    {
        $product = $this->product(['average_cost' => 20]);
        app(StockService::class)->in($product, StockMovementType::OPENING_BALANCE, 10, ['unit_cost' => 20]);
        [$method] = $this->paymentSetup();
        $this->actingAs($this->owner, 'sanctum');

        $purchaseId = $this->postJson('/api/professional/purchases', [
            'supplier_id' => $this->supplier->id,
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 26]],
            'payment' => ['payment_method_id' => $method->id, 'installments' => 2, 'first_due_date' => '2026-10-20'],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/purchases/{$purchaseId}/receive")->assertOk();

        $purchase = Purchase::findOrFail($purchaseId);
        $purchase->installments()->where('number', 1)->update(['status' => 'paid', 'paid_at' => now()]);

        $this->postJson("/api/professional/purchases/{$purchaseId}/cancel", ['reason' => 'Nota emitida errada'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.installments.0.status', 'paid')
            ->assertJsonPath('data.installments.1.status', 'cancelled');

        $product->refresh();
        $this->assertSame(10, $product->stock_quantity);
        $this->assertSame(20.0, (float) $product->average_cost);
        // Estorno é movimento novo; o de entrada continua no livro.
        $this->assertSame(['opening_balance', 'purchase_in', 'return_out'], StockMovement::where('product_id', $product->id)->orderBy('id')->pluck('type')->map->value->all());
        $this->assertLedgerMatches($product);
    }

    public function test_the_same_invoice_key_cannot_be_entered_twice_and_other_clinics_cannot_see_it(): void
    {
        $product = $this->product();
        $this->actingAs($this->owner, 'sanctum');
        $payload = [
            'supplier_id' => $this->supplier->id,
            'invoice_key' => '3526 0912 3456 7800 0190 5500 1000 0012 3410 0001 2345',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1]],
        ];

        $id = $this->postJson('/api/professional/purchases', $payload)->assertCreated()->json('data.id');
        $this->postJson('/api/professional/purchases', $payload)->assertStatus(422);

        $stranger = \App\Models\User::factory()->professional()->create();
        $this->actingAs($stranger, 'sanctum')->getJson("/api/professional/purchases/{$id}")->assertNotFound();
        $this->postJson('/api/professional/purchases', ['supplier_id' => $this->supplier->id, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_cost' => 1]]])
            ->assertNotFound();
    }

    public function test_an_item_without_product_or_new_product_is_rejected(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/professional/purchases', [
                'supplier_id' => $this->supplier->id,
                'items' => [['description_on_invoice' => 'ITEM SEM VINCULO', 'quantity' => 1, 'unit_cost' => 1]],
            ])->assertStatus(422)->assertJsonValidationErrors('items.0.product_id');
    }

    /** @return array{0: PaymentMethod, 1: FinancialAccount} */
    private function paymentSetup(): array
    {
        $account = FinancialAccount::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'name' => 'Banco', 'type' => 'checking', 'opening_balance' => 0,
        ]);
        $method = PaymentMethod::create([
            'organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id,
            'name' => 'Boleto', 'kind' => 'boleto', 'direction' => 'out',
        ]);

        return [$method, $account];
    }
}
