<?php

namespace Tests\Feature\Commercial;

use App\Models\SoldPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Commercial\Concerns\BuildsCounterFixtures;
use Tests\Feature\Commercial\Concerns\BuildsPackageFixtures;
use Tests\TestCase;

/**
 * Critérios de aceite do docs/gap-simplesvet/specs/10-pacotes-de-servicos-vendidos-spec.md:
 * venda paga de um item `ServicePackage` ativa o saldo de sessões do animal.
 */
class SoldPackageActivationTest extends TestCase
{
    use BuildsCounterFixtures;
    use BuildsPackageFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildClinic();
    }

    public function test_paying_a_sale_with_a_package_item_creates_the_sold_package_with_full_balance(): void
    {
        $package = $this->makeServicePackage(quantity: 10);

        $soldPackage = $this->sellPackageToPet($package, $this->pet, $this->tutor);

        $this->assertSame($this->pet->id, $soldPackage->pet_id);
        $this->assertSame($this->tutor->id, $soldPackage->client_id);
        $this->assertSame(1, $soldPackage->items()->count());

        $item = $soldPackage->items()->first();
        $this->assertSame(10, $item->quantity_total);
        $this->assertSame(0, $item->quantity_used);
        $this->assertSame($soldPackage->sold_at->addDays(90)->toDateString(), $soldPackage->expires_at->toDateString());
    }

    public function test_selling_a_package_on_a_sale_without_a_pet_is_rejected_before_payment(): void
    {
        $package = $this->makeServicePackage();
        $this->openRegisterFor($this->receptionist);

        $saleId = $this->postJson('/api/professional/sales', ['kind' => 'sale'])->json('data.id');

        $this->postJson("/api/professional/sales/{$saleId}/items", [
            'sellable_type' => 'package', 'sellable_id' => $package->id,
        ])->assertStatus(422)->assertJsonValidationErrors('pet_id');

        $this->assertSame(0, SoldPackage::count());
    }

    public function test_a_package_with_a_fixed_expiry_date_uses_that_date_regardless_of_sale_day(): void
    {
        $package = $this->makeServicePackage([
            'validity_type' => 'fixed_date',
            'validity_days' => null,
            'fixed_expires_at' => '2027-01-15',
        ]);

        $soldPackage = $this->sellPackageToPet($package, $this->pet, $this->tutor);

        $this->assertSame('2027-01-15', $soldPackage->expires_at->toDateString());
    }
}
