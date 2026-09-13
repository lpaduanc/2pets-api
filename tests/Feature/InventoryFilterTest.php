<?php

namespace Tests\Feature;

use App\Models\Inventory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $professional;

    protected function setUp(): void
    {
        parent::setUp();

        $this->professional = User::factory()->professional()->create();
    }

    /**
     * Bug: `InventoryController::index` ignored `low_stock`, `category` and search entirely,
     * always returning the whole table with `->get()`.
     */
    public function test_low_stock_filter_returns_only_items_at_or_below_minimum(): void
    {
        $this->createItem('Ração baixa', quantity: 2, minQuantity: 5);
        $this->createItem('Ração ok', quantity: 20, minQuantity: 5);

        Sanctum::actingAs($this->professional);

        $response = $this->getJson('/api/professional/inventory?low_stock=true');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('item_name');

        $this->assertTrue($names->contains('Ração baixa'));
        $this->assertFalse($names->contains('Ração ok'));
    }

    public function test_category_filter_returns_only_matching_category(): void
    {
        $this->createItem('Amoxicilina', quantity: 10, minQuantity: 2, category: 'medication');
        $this->createItem('Seringa', quantity: 10, minQuantity: 2, category: 'supply');

        Sanctum::actingAs($this->professional);

        $response = $this->getJson('/api/professional/inventory?category=medication');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('item_name');

        $this->assertTrue($names->contains('Amoxicilina'));
        $this->assertFalse($names->contains('Seringa'));
    }

    public function test_search_filter_matches_item_name_case_insensitively(): void
    {
        $this->createItem('Ração Premium', quantity: 10, minQuantity: 2);
        $this->createItem('Coleira', quantity: 10, minQuantity: 2);

        Sanctum::actingAs($this->professional);

        $response = $this->getJson('/api/professional/inventory?search=ração');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('item_name');

        $this->assertTrue($names->contains('Ração Premium'));
        $this->assertFalse($names->contains('Coleira'));
    }

    private function createItem(
        string $name,
        int $quantity,
        int $minQuantity,
        string $category = 'supply',
    ): Inventory {
        return Inventory::create([
            'professional_id' => $this->professional->id,
            'item_name' => $name,
            'category' => $category,
            'quantity' => $quantity,
            'min_quantity' => $minQuantity,
        ]);
    }
}
