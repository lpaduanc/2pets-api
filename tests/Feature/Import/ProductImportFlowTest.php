<?php

namespace Tests\Feature\Import;

use App\Enums\Import\ImportRowStatus;
use App\Enums\Import\ImportStatus;
use App\Enums\StockMovementType;
use App\Models\DataImport;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Item 26 do backlog gap-simplesvet — fluxo ponta a ponta de importação de `products`: saldo
 * inicial nunca grava direto na coluna, vira `StockMovementType::OPENING_BALANCE` (doc 07).
 */
class ProductImportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_products_can_be_imported_without_any_client(): void
    {
        $vet = User::factory()->professional()->create();
        $import = $this->readyImportFor($vet, [
            'name' => 'Ração Premium',
            'code' => null,
            'sku' => null,
            'gtin' => null,
            'ncm' => null,
            'unit_of_sale' => 'UN',
            'brand_id' => null,
            'product_group_id' => null,
            'price' => 50.0,
            'average_cost' => null,
            'markup_percent' => null,
            'stock_quantity' => 20,
        ]);

        $this->actingAs($vet)
            ->postJson("/api/data-imports/{$import->id}/execute", ['duplicate_strategy' => 'skip'])
            ->assertStatus(202);

        $product = Product::where('name', 'Ração Premium')->first();
        $this->assertNotNull($product);
        $this->assertSame(20, $product->stock_quantity);
        $this->assertSame(ImportStatus::COMPLETED->value, $import->fresh()->status);
    }

    public function test_opening_stock_balance_is_recorded_as_a_stock_movement_not_a_raw_write(): void
    {
        $vet = User::factory()->professional()->create();
        $import = $this->readyImportFor($vet, [
            'name' => 'Vermífugo',
            'code' => null,
            'sku' => null,
            'gtin' => null,
            'ncm' => null,
            'unit_of_sale' => null,
            'brand_id' => null,
            'product_group_id' => null,
            'price' => 30.0,
            'average_cost' => null,
            'markup_percent' => null,
            'stock_quantity' => 10,
        ]);

        $this->actingAs($vet)
            ->postJson("/api/data-imports/{$import->id}/execute", ['duplicate_strategy' => 'skip'])
            ->assertStatus(202);

        $product = Product::where('name', 'Vermífugo')->first();
        $this->assertSame(1, StockMovement::where('product_id', $product->id)
            ->where('type', StockMovementType::OPENING_BALANCE->value)
            ->count());
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function readyImportFor(User $professional, array $normalized): DataImport
    {
        $import = DataImport::create([
            'professional_id' => $professional->id,
            'user_id' => $professional->id,
            'entity' => 'products',
            'file_path' => 'data-imports/fake.csv',
            'status' => ImportStatus::READY->value,
            'valid_rows' => 1,
            'batch_uuid' => (string) Str::uuid(),
        ]);

        $import->rows()->create([
            'row_number' => 1,
            'raw' => ['nome' => $normalized['name']],
            'normalized' => $normalized,
            'status' => ImportRowStatus::VALID->value,
        ]);

        return $import;
    }
}
