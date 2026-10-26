<?php

namespace Tests\Feature;

use App\Enums\CatalogImageStatus;
use App\Jobs\FetchCatalogProductImage;
use App\Models\CatalogBrand;
use App\Models\CatalogProduct;
use App\Models\User;
use Database\Seeders\CatalogProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Catálogo global de produtos (`/api/admin/catalog-products`): só o admin da plataforma gere;
 * foto vem do Cosmos por EAN ou de upload, nunca sobrescrevendo upload manual.
 */
class AdminCatalogProductTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $overrides = []): CatalogProduct
    {
        $brand = CatalogBrand::findOrCreateByName('Golden');

        return CatalogProduct::create($overrides + [
            'catalog_brand_id' => $brand->id,
            'category' => 'dry_food',
            'species' => 'dog',
            'line_name' => 'Ração Golden Fórmula Cães Adultos Frango',
            'name' => 'Ração Golden Fórmula Cães Adultos Frango 15 kg',
            'variant_label' => '15 kg',
            'net_weight_grams' => 15000,
            'ean' => '7896181212345',
            'dedup_key' => 'golden|racao-golden-formula-caes-adultos-frango|15000',
        ]);
    }

    public function test_non_admin_cannot_list_catalog(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/admin/catalog-products')
            ->assertForbidden();
    }

    public function test_admin_lists_and_filters_by_search_and_ean(): void
    {
        $this->product();
        $this->product([
            'name' => 'Ração Whiskas Gatos Adultos Carne 1 kg',
            'species' => 'cat',
            'ean' => '7896029012345',
            'dedup_key' => 'whiskas|x|1000',
        ]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/admin/catalog-products?q=golden adultos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.brand.name', 'Golden');

        $this->actingAs($admin)->getJson('/api/admin/catalog-products?q=7896029012345')
            ->assertOk()
            ->assertJsonPath('data.0.species', 'cat');
    }

    public function test_admin_updates_name_and_uploads_image(): void
    {
        Storage::fake('public');
        $product = $this->product();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->putJson("/api/admin/catalog-products/{$product->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($admin)
            ->post("/api/admin/catalog-products/{$product->id}/image", ['image' => UploadedFile::fake()->image('r.jpg')])
            ->assertOk()
            ->assertJsonPath('data.image_status', CatalogImageStatus::UPLOADED->value);
    }

    public function test_fetch_images_requires_cosmos_token(): void
    {
        config(['services.cosmos.token' => null]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/admin/catalog-products/fetch-images')
            ->assertStatus(422)
            ->assertJsonPath('code', 'cosmos_not_configured');
    }

    public function test_fetch_images_queues_only_items_without_image(): void
    {
        Queue::fake();
        config(['services.cosmos.token' => 'test-token']);
        $pending = $this->product();
        $this->product([
            'ean' => '7896029012345',
            'dedup_key' => 'golden|y|3000',
            'image_status' => CatalogImageStatus::UPLOADED->value,
        ]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/api/admin/catalog-products/fetch-images')
            ->assertStatus(202);

        Queue::assertPushed(FetchCatalogProductImage::class, 1);
        Queue::assertPushed(FetchCatalogProductImage::class, fn ($job) => (fn () => $this->catalogProductId)->call($job) === $pending->id);
    }

    public function test_job_stores_cosmos_image_and_ncm(): void
    {
        Storage::fake('public');
        config(['services.cosmos.token' => 'test-token', 'services.cosmos.base_url' => 'https://cosmos.test']);
        Http::fake([
            'cosmos.test/gtins/*' => Http::response(['thumbnail' => 'https://img.test/1.jpg', 'ncm' => ['code' => '23091000']]),
            'img.test/*' => Http::response('binary', 200, ['Content-Type' => 'image/jpeg']),
        ]);
        $product = $this->product();

        FetchCatalogProductImage::dispatchSync($product->id);

        $product->refresh();
        $this->assertSame(CatalogImageStatus::FOUND, $product->image_status);
        $this->assertSame('23091000', $product->ncm);
        Storage::disk('public')->assertExists('catalog/7896181212345.jpg');
    }

    public function test_job_marks_not_found_when_ean_unknown(): void
    {
        config(['services.cosmos.token' => 'test-token', 'services.cosmos.base_url' => 'https://cosmos.test']);
        Http::fake(['cosmos.test/*' => Http::response([], 404)]);
        $product = $this->product();

        FetchCatalogProductImage::dispatchSync($product->id);

        $this->assertSame(CatalogImageStatus::NOT_FOUND, $product->refresh()->image_status);
    }

    public function test_seeder_loads_dataset_without_duplicates_and_is_idempotent(): void
    {
        $this->seed(CatalogProductSeeder::class);
        $count = CatalogProduct::count();
        $this->seed(CatalogProductSeeder::class);

        $this->assertGreaterThan(8000, $count);
        $this->assertSame($count, CatalogProduct::count());
        // Itens sem EAN (raros) ficam de fora: `count(distinct)` ignora NULL.
        $this->assertSame(CatalogProduct::whereNotNull('ean')->count(), CatalogProduct::distinct()->count('ean'));
    }
}
