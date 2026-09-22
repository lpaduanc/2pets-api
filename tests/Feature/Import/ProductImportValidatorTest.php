<?php

namespace Tests\Feature\Import;

use App\Models\Brand;
use App\Models\DataImport;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Import\BrazilianFormatParser;
use App\Services\Import\ProductCatalogResolver;
use App\Services\Import\ProductImportValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Item 26 do backlog gap-simplesvet — produto reaproveita marca/grupo do item 08; preço OU
 * markup é obrigatório, mesma regra de `StoreProductRequest`.
 */
class ProductImportValidatorTest extends TestCase
{
    use RefreshDatabase;

    private ProductImportValidator $validator;

    private User $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new ProductImportValidator(
            new BrazilianFormatParser,
            new ProductCatalogResolver(new CommercialScopeResolver),
        );
        $this->professional = User::factory()->professional()->create();
    }

    private function importFor(User $professional): DataImport
    {
        return DataImport::create([
            'professional_id' => $professional->id,
            'user_id' => $professional->id,
            'entity' => 'products',
            'file_path' => 'data-imports/fake.csv',
            'status' => 'validating',
            'batch_uuid' => (string) Str::uuid(),
        ]);
    }

    public function test_valid_row_normalizes_brazilian_decimal_price(): void
    {
        $result = $this->validator->validate([
            'name' => 'Ração Premium 10kg',
            'price' => '1.234,56',
        ], $this->importFor($this->professional));

        $this->assertTrue($result['valid']);
        $this->assertSame(1234.56, $result['normalized']['price']);
    }

    public function test_row_without_price_or_markup_is_invalid(): void
    {
        $result = $this->validator->validate(['name' => 'Ração'], $this->importFor($this->professional));

        $this->assertFalse($result['valid']);
        $this->assertContains(
            'Informe o preço de venda ou um markup para calculá-lo a partir do custo.',
            $result['errors'],
        );
    }

    public function test_row_with_only_markup_is_valid(): void
    {
        $result = $this->validator->validate([
            'name' => 'Ração',
            'average_cost' => '10,00',
            'markup_percent' => '40',
        ], $this->importFor($this->professional));

        $this->assertTrue($result['valid']);
    }

    public function test_unknown_brand_name_creates_a_new_brand_scoped_to_the_owner(): void
    {
        $result = $this->validator->validate([
            'name' => 'Ração',
            'price' => '50',
            'brand' => 'Marca Nova',
        ], $this->importFor($this->professional));

        $this->assertTrue($result['valid']);
        $this->assertNotNull($result['normalized']['brand_id']);
        $this->assertDatabaseHas('brands', [
            'id' => $result['normalized']['brand_id'],
            'name' => 'Marca Nova',
            'professional_id' => $this->professional->id,
        ]);
    }

    public function test_existing_brand_is_reused_case_insensitively(): void
    {
        $brand = Brand::create([
            'professional_id' => $this->professional->id,
            'name' => 'Royal Canin',
            'active' => true,
        ]);

        $result = $this->validator->validate([
            'name' => 'Ração',
            'price' => '50',
            'brand' => 'royal canin',
        ], $this->importFor($this->professional));

        $this->assertSame($brand->id, $result['normalized']['brand_id']);
        $this->assertSame(1, Brand::count());
    }
}
