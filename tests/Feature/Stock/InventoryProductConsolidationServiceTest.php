<?php

namespace Tests\Feature\Stock;

use App\Enums\InventoryMovementType;
use App\Enums\ProductPurpose;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PetDeworming;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use App\Models\Vaccination;
use App\Services\Stock\InventoryProductConsolidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md — a migração de dados de
 * `inventories` para `products` (itens 1, 3 e 5 da spec). Não testa PostGIS/geografia, então
 * roda normalmente na suíte sqlite.
 */
class InventoryProductConsolidationServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryProductConsolidationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(InventoryProductConsolidationService::class);
    }

    public function test_vaccine_item_migrates_as_consumable_in_the_vaccinas_group_with_a_batch(): void
    {
        $vet = User::factory()->professional()->create();
        $inventory = Inventory::create([
            'professional_id' => $vet->id,
            'item_name' => 'Vacina V10',
            'category' => 'vaccine',
            'quantity' => 20,
            'cost_price' => 45,
            'selling_price' => 90,
            'expiry_date' => now()->addYear(),
        ]);

        $this->service->migrate();

        $product = Product::where('legacy_inventory_id', $inventory->id)->firstOrFail();

        $this->assertSame(ProductPurpose::CONSUMABLE, $product->purpose);
        $this->assertSame('Vacinas', $product->group->name);
        $this->assertTrue($product->controls_stock);
        $this->assertTrue($product->track_batches);
        $this->assertSame(20, $product->stock_quantity);
        $this->assertSame(20, ProductBatch::where('legacy_inventory_id', $inventory->id)->firstOrFail()->quantity);
    }

    public function test_equipment_item_migrates_without_controlling_stock_or_entering_price_list(): void
    {
        $vet = User::factory()->professional()->create();
        $inventory = Inventory::create([
            'professional_id' => $vet->id,
            'item_name' => 'Aparelho de Raio-X',
            'category' => 'equipment',
            'quantity' => 1,
            'selling_price' => 50000,
        ]);

        $this->service->migrate();

        $product = Product::where('legacy_inventory_id', $inventory->id)->firstOrFail();

        $this->assertFalse($product->controls_stock);
        $this->assertFalse($product->show_in_price_list);
        $this->assertSame(1, $product->stock_quantity);
        $this->assertSame(0, ProductBatch::where('product_id', $product->id)->count());
    }

    public function test_duplicate_inventory_rows_with_the_same_name_merge_into_one_product_with_two_batches(): void
    {
        $vet = User::factory()->professional()->create();
        $first = Inventory::create([
            'professional_id' => $vet->id,
            'item_name' => 'Vacina Antirrábica',
            'category' => 'vaccine',
            'quantity' => 10,
            'expiry_date' => now()->addMonths(6),
        ]);
        $second = Inventory::create([
            'professional_id' => $vet->id,
            'item_name' => 'vacina antirrábica ', // mesmo nome, caixa/espaço diferentes
            'category' => 'vaccine',
            'quantity' => 5,
            'expiry_date' => now()->addYear(),
        ]);

        $this->service->migrate();

        $this->assertSame(1, Product::where('professional_id', $vet->id)->count());
        $product = Product::where('legacy_inventory_id', $first->id)->firstOrFail();
        $this->assertSame(15, $product->stock_quantity);
        $this->assertSame(10, ProductBatch::where('legacy_inventory_id', $first->id)->value('quantity'));
        $this->assertSame(5, ProductBatch::where('legacy_inventory_id', $second->id)->value('quantity'));
    }

    public function test_movement_history_is_replayed_and_reconciled_to_the_original_balance(): void
    {
        $vet = User::factory()->professional()->create();
        $inventory = Inventory::create([
            'professional_id' => $vet->id,
            'item_name' => 'Dipirona',
            'category' => 'medication',
            'quantity' => 12,
        ]);
        InventoryMovement::create([
            'inventory_id' => $inventory->id,
            'professional_id' => $vet->id,
            'type' => InventoryMovementType::PURCHASE_IN,
            'quantity_delta' => 20,
        ]);
        InventoryMovement::create([
            'inventory_id' => $inventory->id,
            'professional_id' => $vet->id,
            'type' => InventoryMovementType::ADJUSTMENT_DECREASE,
            'quantity_delta' => -8,
        ]);

        $this->service->migrate();

        $product = Product::where('legacy_inventory_id', $inventory->id)->firstOrFail();
        $this->assertSame(12, $product->stock_quantity);
        $this->assertSame(2, $product->stockMovements()->count());
    }

    public function test_running_migrate_twice_does_not_duplicate_products_or_movements(): void
    {
        $vet = User::factory()->professional()->create();
        Inventory::create([
            'professional_id' => $vet->id,
            'item_name' => 'Soro Fisiológico',
            'category' => 'supply',
            'quantity' => 40,
        ]);

        $first = $this->service->migrate();
        $second = $this->service->migrate();

        $this->assertSame(1, $first['groups_migrated']);
        $this->assertSame(0, $second['groups_migrated']);
        $this->assertSame(1, Product::where('professional_id', $vet->id)->count());
    }

    public function test_vaccination_and_deworming_inventory_id_backfill_to_product_and_batch(): void
    {
        $vet = User::factory()->professional()->create();
        $pet = \App\Models\Pet::factory()->create();

        $vaccineInventory = Inventory::create([
            'professional_id' => $vet->id,
            'item_name' => 'Vacina Backfill',
            'category' => 'vaccine',
            'quantity' => 5,
            'expiry_date' => now()->addYear(),
        ]);
        $vaccination = Vaccination::create([
            'pet_id' => $pet->id,
            'inventory_id' => $vaccineInventory->id,
            'vaccine_name' => 'Vacina Backfill',
            'application_date' => now(),
        ]);

        $dewormingInventory = Inventory::create([
            'professional_id' => $vet->id,
            'item_name' => 'Vermifugo Backfill',
            'category' => 'medication',
            'quantity' => 3,
        ]);
        $deworming = PetDeworming::create([
            'pet_id' => $pet->id,
            'inventory_id' => $dewormingInventory->id,
            'product_name' => 'Vermifugo Backfill',
            'applied_date' => now(),
        ]);

        $summary = $this->service->migrate();

        $vaccination->refresh();
        $deworming->refresh();

        $this->assertSame(2, $summary['clinical_records_relinked']);
        $this->assertNotNull($vaccination->product_id);
        $this->assertNotNull($vaccination->product_batch_id);
        $this->assertSame(
            Product::where('legacy_inventory_id', $vaccineInventory->id)->value('id'),
            $vaccination->product_id,
        );
        $this->assertSame(
            Product::where('legacy_inventory_id', $dewormingInventory->id)->value('id'),
            $deworming->product_id,
        );
    }

    public function test_free_text_supplier_is_matched_or_created_and_linked_to_the_product(): void
    {
        $vet = User::factory()->professional()->create();
        Inventory::create([
            'professional_id' => $vet->id,
            'item_name' => 'Ração Terapêutica',
            'category' => 'supply',
            'quantity' => 4,
            'supplier' => 'Fornecedor Novo Ltda',
        ]);

        $this->service->migrate();

        $product = Product::where('professional_id', $vet->id)->firstOrFail();
        $this->assertNotNull($product->last_supplier_id);
        $this->assertSame('Fornecedor Novo Ltda', $product->lastSupplier->legal_name);
    }
}
