<?php

namespace Tests\Feature\Stock\Concerns;

use App\Enums\StockDirection;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/** Clínica com dono e produtos de catálogo — cenário dos docs gap-simplesvet/06 e 07. */
trait BuildsStockFixtures
{
    protected Organization $clinic;

    protected User $owner;

    protected function buildStockClinic(): void
    {
        // Ver comentário equivalente em `BuildsCounterFixtures::buildClinic()` (item 22).
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->clinic = Organization::factory()->create();
        $this->owner = User::factory()->professional()->create();

        OrganizationMember::factory()->owner()->for($this->clinic, 'organization')
            ->create(['user_id' => $this->owner->id]);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function product(array $overrides = []): Product
    {
        return Product::create($overrides + [
            'professional_id' => $this->owner->id,
            'organization_id' => $this->clinic->id,
            'name' => 'Ração Premium 3kg',
            'sku' => 'SKU-'.uniqid(),
            'price' => 100.00,
            'stock_quantity' => 0,
            'controls_stock' => true,
            'is_active' => true,
        ]);
    }

    /** Invariante do doc 07: o saldo é reconstituível somando o livro. */
    protected function assertLedgerMatches(Product $product): void
    {
        $sum = StockMovement::where('product_id', $product->id)->get()
            ->sum(fn (StockMovement $m) => $m->direction === StockDirection::IN ? $m->quantity : -$m->quantity);

        $this->assertSame((int) $product->fresh()->stock_quantity, (int) $sum, 'SUM(movimentos) diverge de products.stock_quantity');
    }
}
