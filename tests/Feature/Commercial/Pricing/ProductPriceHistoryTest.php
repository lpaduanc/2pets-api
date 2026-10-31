<?php

namespace Tests\Feature\Commercial\Pricing;

use App\Enums\PriceChangeSource;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\Supplier;
use App\Services\Import\ImportedProductProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\Stock\Concerns\BuildsStockFixtures;
use Tests\TestCase;

/**
 * `product_price_history` (docs/gap-petflow/PLANO.md, 1.4): append-only, uma linha por preço que
 * passou a valer — cadastro manual, recebimento de compra e importação —, com custo, markup,
 * usuário, origem e o par organização/profissional do produto.
 */
class ProductPriceHistoryTest extends TestCase
{
    use BuildsStockFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildStockClinic();
    }

    public function test_manual_registration_records_the_initial_price_and_each_change(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $id = $this->postJson('/api/professional/products', ['name' => 'Ração', 'sku' => 'R-1', 'price' => '50.00', 'average_cost' => '40.0000'])
            ->assertCreated()
            ->json('data.id');

        // Mesmo preço: salvar de novo não grava.
        $this->putJson("/api/professional/products/{$id}", ['name' => 'Ração adulto', 'price' => '50.00'])->assertOk();
        // Preço e custo juntos: a linha leva o custo NOVO.
        $this->putJson("/api/professional/products/{$id}", ['price' => '60.00', 'average_cost' => '45.0000'])->assertOk();

        $rows = ProductPriceHistory::where('product_id', $id)->orderBy('id')->get();
        $this->assertSame(
            [['50.00', '40.0000', '25.0000', 'manual'], ['60.00', '45.0000', '33.3333', 'manual']],
            $rows->map(fn (ProductPriceHistory $row): array => [$row->price, $row->cost, $row->markup_percent, $row->source->value])->all(),
        );
        $this->assertTrue($rows->every(fn (ProductPriceHistory $row): bool => $row->user_id === $this->owner->id
            && $row->organization_id === $this->clinic->id && $row->professional_id === $this->owner->id));
    }

    public function test_purchase_records_the_applied_sale_price_and_the_new_product_price(): void
    {
        $product = $this->product(['price' => 30, 'average_cost' => 20, 'markup_percent' => 50]);
        $supplier = Supplier::create(['organization_id' => $this->clinic->id, 'professional_id' => $this->owner->id, 'legal_name' => 'Distribuidora X']);
        $this->actingAs($this->owner, 'sanctum');

        $purchaseId = $this->postJson('/api/professional/purchases', [
            'supplier_id' => $supplier->id,
            'entered_at' => '2026-09-20 10:00:00',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_cost' => 26, 'applied_sale_price' => 39],
                ['new_product' => ['name' => 'Petisco dental', 'controls_stock' => true], 'quantity' => 12, 'unit_cost' => 4, 'markup_percent' => 100],
            ],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/professional/purchases/{$purchaseId}/receive")->assertOk();

        $this->assertSame(
            [['39.00', PriceChangeSource::PURCHASE]],
            ProductPriceHistory::where('product_id', $product->id)->get()->map(fn (ProductPriceHistory $row): array => [$row->price, $row->source])->all(),
        );
        $created = Product::where('name', 'Petisco dental')->sole();
        $this->assertSame(1, ProductPriceHistory::where('product_id', $created->id)->where('source', 'purchase')->count());
    }

    public function test_import_records_the_imported_price(): void
    {
        $product = app(ImportedProductProvisioner::class)->create([
            'name' => 'Coleira', 'sku' => 'C-1', 'code' => null, 'gtin' => null, 'ncm' => null, 'unit_of_sale' => 'UN',
            'brand_id' => null, 'product_group_id' => null, 'price' => '25.00', 'average_cost' => '10.0000',
            'markup_percent' => null, 'stock_quantity' => 0,
        ], $this->owner);

        $row = ProductPriceHistory::where('product_id', $product->id)->sole();
        $this->assertSame(['25.00', PriceChangeSource::IMPORT, $this->owner->id], [$row->price, $row->source, $row->user_id]);
    }

    public function test_history_is_append_only(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $id = $this->postJson('/api/professional/products', ['name' => 'Ração', 'sku' => 'R-2', 'price' => '50.00'])->json('data.id');
        $row = ProductPriceHistory::where('product_id', $id)->sole();

        $this->expectException(LogicException::class);
        $row->update(['price' => '1.00']);
    }
}
